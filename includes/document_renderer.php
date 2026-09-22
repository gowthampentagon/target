<?php
// includes/document_renderer.php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/events.php';

/**
 * Resolves relative paths from any folder level to the project root.
 */
function resolveDocPath(string $path): string {
    if (empty($path)) return '';
    // If it's already an absolute or base64 url, return as is
    if (strpos($path, 'data:') === 0 || strpos($path, 'http:') === 0 || strpos($path, 'https:') === 0) {
        return $path;
    }
    
    // Recursively find the root directory of the application
    $rootPath = '';
    for ($i = 0; $i < 5; $i++) {
        if (file_exists($rootPath . 'config/db.php')) {
            break;
        }
        $rootPath .= '../';
    }
    return $rootPath . $path;
}

/**
 * Renders the HTML block for a dynamically styled document.
 * 
 * @param string $documentType e.g., 'certificate', 'competitor_card', 'start_sheet', 'score_sheet', 'rank_list'
 * @param array $variables Key-value pairs for text replacements (e.g., ['participant_name' => 'John Doe'])
 * @param string $photoSrc Image path or base64 string for participant photo placeholder
 * @param array $tableData Optional data for table components: ['headers' => [...], 'rows' => [...]]
 * @return string The compiled HTML container
 */
function getDynamicDocumentHTML(string $documentType, array $variables, string $photoSrc = '', array $tableData = []): string {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT canvas_data, background_path FROM document_templates WHERE document_type = ? LIMIT 1");
    $stmt->execute([$documentType]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // Score sheet specific fallback check
    if (!$row && (strpos($documentType, 'score_sheet') === 0)) {
        $fallbacks = ['score_sheet', 'score_sheet_air_rifle_pistol'];
        foreach ($fallbacks as $fbType) {
            $stmtFb = $pdo->prepare("SELECT canvas_data, background_path FROM document_templates WHERE document_type = ? LIMIT 1");
            $stmtFb->execute([$fbType]);
            $row = $stmtFb->fetch(PDO::FETCH_ASSOC);
            if ($row) break;
        }
    }

    $canvas = null;
    if ($row && !empty($row['canvas_data'])) {
        $canvas = json_decode($row['canvas_data'], true);
    }

    // Self-healing: if database row is missing or has invalid/empty canvas JSON, load from JSON file
    if (!$canvas) {
        $jsonFile = __DIR__ . '/../config/document_templates.json';
        if (file_exists($jsonFile)) {
            $templates = json_decode(file_get_contents($jsonFile), true);
            
            // Try matching target document type
            $sourceType = isset($templates[$documentType]) ? $documentType : null;
            
            // Fallback for score sheets if main type not found in JSON
            if (!$sourceType && (strpos($documentType, 'score_sheet') === 0)) {
                foreach (['score_sheet', 'score_sheet_air_rifle_pistol'] as $fbType) {
                    if (isset($templates[$fbType])) {
                        $sourceType = $fbType;
                        break;
                    }
                }
            }
            
            if ($sourceType && isset($templates[$sourceType])) {
                $canvas = $templates[$sourceType]['canvas_data'] ?? null;
                if ($canvas) {
                    $row = [
                        'background_path' => $templates[$sourceType]['background_path'] ?? null,
                        'canvas_data' => json_encode($canvas)
                    ];
                    // Save/restore corrected template to database
                    try {
                        $ins = $pdo->prepare("INSERT INTO document_templates (document_type, background_path, canvas_data) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE background_path = VALUES(background_path), canvas_data = VALUES(canvas_data)");
                        $ins->execute([$documentType, $row['background_path'], $row['canvas_data']]);
                    } catch (Exception $e) {}
                }
            }
        }
    }

    if (!$canvas) {
        return '<div style="padding:20px; color:#ff6b6b; font-weight:bold;">Invalid canvas JSON or template not found for: ' . htmlspecialchars($documentType) . '</div>';
    }

    $width = $canvas['width'] ?? 794;
    $height = $canvas['height'] ?? 1122;
    $background = $canvas['background'] ?? $row['background_path'] ?? '';
    
    $bgStyle = 'background-color: #ffffff;';
    if (!empty($background)) {
        if (strpos($background, '#') === 0 || strpos($background, 'rgb') === 0) {
            $bgStyle = "background-color: " . $background . ";";
        } else {
            $bgStyle = "background-image: url('" . resolveDocPath($background) . "'); background-size: cover; background-position: center;";
        }
    }
        
    // Page border settings
    $pageBorderStyle = $canvas['pageBorderStyle'] ?? 'none';
    $pageBorderWidth = (int)($canvas['pageBorderWidth'] ?? 0);
    $pageBorderColor = $canvas['pageBorderColor'] ?? '#000000';
    $pageBorderRadius = (int)($canvas['pageBorderRadius'] ?? 0);
    $pageBorderInset = (int)($canvas['pageBorderInset'] ?? 0);
    
    $mainBorderStyle = '';
    if ($pageBorderStyle !== 'none' && $pageBorderWidth > 0 && $pageBorderInset === 0) {
        $mainBorderStyle = "border: {$pageBorderWidth}px {$pageBorderStyle} {$pageBorderColor}; border-radius: {$pageBorderRadius}px;";
    }
    
    $overflowStyle = "height: {$height}px; overflow: hidden;";
    if ($documentType === 'start_sheet' || $documentType === 'rank_list') {
        $overflowStyle = "height: auto; min-height: {$height}px; padding-bottom: 40px;";
    }
    
    // Main Container
    $html = '<div class="document-canvas-render" style="position: relative; width: ' . $width . 'px; ' . $overflowStyle . ' ' . $bgStyle . ' ' . $mainBorderStyle . ' box-sizing: border-box; background-repeat: no-repeat;">';
    
    // Render inset border overlay if configured
    if ($pageBorderStyle !== 'none' && $pageBorderWidth > 0 && $pageBorderInset > 0) {
        $insetW = $width - ($pageBorderInset * 2);
        $insetH = $height - ($pageBorderInset * 2);
        if ($documentType === 'start_sheet' || $documentType === 'rank_list') {
            $html .= '<div class="page-inset-border-render" style="position: absolute; left: ' . $pageBorderInset . 'px; top: ' . $pageBorderInset . 'px; right: ' . $pageBorderInset . 'px; bottom: ' . $pageBorderInset . 'px; border: ' . $pageBorderWidth . 'px ' . $pageBorderStyle . ' ' . $pageBorderColor . '; border-radius: ' . $pageBorderRadius . 'px; pointer-events: none; box-sizing: border-box; z-index: 2;"></div>';
        } else {
            $html .= '<div class="page-inset-border-render" style="position: absolute; left: ' . $pageBorderInset . 'px; top: ' . $pageBorderInset . 'px; width: ' . $insetW . 'px; height: ' . $insetH . 'px; border: ' . $pageBorderWidth . 'px ' . $pageBorderStyle . ' ' . $pageBorderColor . '; border-radius: ' . $pageBorderRadius . 'px; pointer-events: none; box-sizing: border-box; z-index: 2;"></div>';
        }
    }
    
    // Sort elements by zIndex to ensure correct rendering order
    $elements = $canvas['elements'] ?? [];
    usort($elements, function($a, $b) {
        return ($a['zIndex'] ?? 0) <=> ($b['zIndex'] ?? 0);
    });

    foreach ($elements as $el) {
        $x = $el['x'] ?? 0;
        $y = $el['y'] ?? 0;
        $w = $el['width'] ?? 200;
        $h = $el['height'] ?? 50;
        $z = $el['zIndex'] ?? 10;
        
        $borderStyle = '';
        if (isset($el['borderStyle']) && $el['borderStyle'] !== 'none' && ($el['borderWidth'] ?? 0) > 0) {
            $borderStyle = "border: {$el['borderWidth']}px {$el['borderStyle']} " . ($el['borderColor'] ?? '#000') . ";";
        }
        $borderRadius = isset($el['borderRadius']) ? "border-radius: {$el['borderRadius']}px;" : '';
        
        $style = "position: absolute; left: {$x}px; top: {$y}px; width: {$w}px; height: {$h}px; z-index: {$z}; {$borderStyle} {$borderRadius} box-sizing: border-box; overflow: hidden;";
        if ($el['type'] === 'table') {
            $style = "position: absolute; left: {$x}px; top: {$y}px; width: {$w}px; height: auto; min-height: {$h}px; z-index: {$z}; {$borderStyle} {$borderRadius} box-sizing: border-box;";
        }
        
        if ($el['type'] === 'text') {
            $content = $el['content'] ?? '';
            // Substitution loop
            foreach ($variables as $key => $val) {
                $content = str_replace('{' . $key . '}', (string)$val, $content);
            }
            
            $fontFamily = $el['fontFamily'] ?? 'Inter';
            $fontSize = $el['fontSize'] ?? 16;
            $color = $el['color'] ?? '#000000';
            $bgColor = $el['backgroundColor'] ?? 'transparent';
            $wrapperStyle = $style . " background-color: {$bgColor}; -webkit-print-color-adjust: exact; print-color-adjust: exact;";
            $fontWeight = $el['fontWeight'] ?? 'normal';
            $fontStyle = $el['fontStyle'] ?? 'normal';
            $textAlign = $el['textAlign'] ?? 'left';
            
            $textStyle = "font-family: {$fontFamily}, sans-serif; font-size: {$fontSize}px; color: {$color}; font-weight: {$fontWeight}; font-style: {$fontStyle}; text-align: {$textAlign}; width: 100%; white-space: pre-wrap; line-height: 1.4; display: block;";
            
            $html .= '<div style="' . $wrapperStyle . ' display: flex; align-items: center;"><div style="' . $textStyle . '">' . $content . '</div></div>';
            
        } elseif ($el['type'] === 'line') {
            $bgColor = $el['backgroundColor'] ?? ($el['color'] ?? '#000000');
            $html .= '<div style="' . $style . ' background-color: ' . $bgColor . '; -webkit-print-color-adjust: exact; print-color-adjust: exact;"></div>';

        } elseif ($el['type'] === 'image') {
            $src = $el['src'] ?? '';
            foreach ($variables as $key => $val) {
                $src = str_replace('{' . $key . '}', (string)$val, $src);
            }
            $srcTrim = trim($src);
            if (strpos($srcTrim, '<img') !== false) {
                $html .= '<div style="' . $style . ' display:flex; align-items:center; justify-content:center;">' . $srcTrim . '</div>';
            } elseif (strpos($srcTrim, 'data:') === 0 || strpos($srcTrim, 'http://') === 0 || strpos($srcTrim, 'https://') === 0) {
                $html .= '<div style="' . $style . '"><img src="' . $srcTrim . '" style="max-width:100%; max-height:100%; object-fit:contain; width:100%; height:100%;" /></div>';
            } elseif (!empty($srcTrim)) {
                $html .= '<div style="' . $style . '"><img src="' . resolveDocPath($srcTrim) . '" style="max-width:100%; max-height:100%; object-fit:contain; width:100%; height:100%;" /></div>';
            } else {
                $html .= '<div style="' . $style . '"></div>';
            }
            
        } elseif ($el['type'] === 'photo') {
            $imgSrc = $photoSrc ? resolveDocPath($photoSrc) : resolveDocPath('images/gallery/ssa_action_1.jpg');
            $html .= '<div style="' . $style . '"><img src="' . $imgSrc . '" style="width:100%; height:100%; object-fit:cover;" /></div>';
            
        } elseif ($el['type'] === 'table') {
            $fontFamily = $el['fontFamily'] ?? 'Inter';
            $fontSize = $el['fontSize'] ?? 12;
            $color = $el['color'] ?? '#000000';
            
            $isDarkTheme = (strtolower(substr(trim($color), 0, 4)) === '#fff' || strtolower(trim($color)) === '#ffffff' || strtolower(trim($color)) === '#ddb25e' || strtolower(trim($color)) === '#ADB5BD');
            $headerBg = $isDarkTheme ? '#ADB5BD' : '#f5f5f5';
            $headerTextColor = $isDarkTheme ? '#000000' : $color;
            $bgColor = $el['backgroundColor'] ?? ($isDarkTheme ? '#16171e' : '#ffffff');
            $rowBg = $bgColor;
            
            $gridStyle = $el['gridStyle'] ?? 'full';
            $bStyle = $el['borderStyle'] ?? 'solid';
            $bWidth = isset($el['borderWidth']) ? (int)$el['borderWidth'] : 1;
            $bColor = $el['borderColor'] ?? '#cccccc';
            
            $cellBorder = 'border: 1px solid #cccccc;';
            if ($bStyle !== 'none' && $bWidth > 0) {
                if ($gridStyle === 'full') {
                    $cellBorder = "border: {$bWidth}px {$bStyle} {$bColor};";
                } elseif ($gridStyle === 'horizontal') {
                    $cellBorder = "border-top: none; border-bottom: {$bWidth}px {$bStyle} {$bColor}; border-left: none; border-right: none;";
                } elseif ($gridStyle === 'vertical') {
                    $cellBorder = "border-top: none; border-bottom: none; border-left: {$bWidth}px {$bStyle} {$bColor}; border-right: {$bWidth}px {$bStyle} {$bColor};";
                } elseif ($gridStyle === 'outer' || $gridStyle === 'none') {
                    $cellBorder = "border: none;";
                }
            }
            
            $tableBorder = '';
            if ($gridStyle === 'outer' && $bStyle !== 'none' && $bWidth > 0) {
                $tableBorder = "border: {$bWidth}px {$bStyle} {$bColor};";
            }
            
            $displayHeaders = !empty($el['headers']) ? $el['headers'] : ($tableData['headers'] ?? []);

            // Helper closure to build table elements with spans and template style matching
            $renderTableHtml = function($displayHeaders, $displayRows, $el, $cellBorder, $headerBg, $headerTextColor, $rowBg) use ($variables) {
                $R = count($displayRows);
                $C = count($displayHeaders);
                $occupied = array_fill(0, $R, array_fill(0, $C, false));
                
                $tableHtml = '';
                if (!empty($displayHeaders)) {
                    $tableHtml .= '<thead style="background: ' . $headerBg . '; color: ' . $headerTextColor . '; font-weight: bold;"><tr>';
                    foreach ($displayHeaders as $cIdx => $header) {
                        $hText = '';
                        $hColspan = 1;
                        $hRowspan = 1;
                        $hStyle = '';
                        
                        $tplHeader = $el['headers'][$cIdx] ?? null;
                        
                        if (is_array($header)) {
                            $hText = $header['text'] ?? '';
                            $hColspan = $header['colspan'] ?? 1;
                            $hRowspan = $header['rowspan'] ?? 1;
                            if (!empty($header['style']['backgroundColor'])) {
                                $hStyle .= 'background-color: ' . $header['style']['backgroundColor'] . ' !important; background: ' . $header['style']['backgroundColor'] . ' !important;';
                            }
                            if (!empty($header['style']['color'])) {
                                $hStyle .= 'color: ' . $header['style']['color'] . ' !important;';
                            }
                            if (!empty($header['style']['fontFamily'])) {
                                $hStyle .= 'font-family: \'' . $header['style']['fontFamily'] . '\', sans-serif !important;';
                            }
                            if (!empty($header['style']['fontSize'])) {
                                $hStyle .= 'font-size: ' . $header['style']['fontSize'] . 'px !important;';
                            }
                            if (!empty($header['style']['fontWeight'])) {
                                $hStyle .= 'font-weight: ' . $header['style']['fontWeight'] . ' !important;';
                            }
                            if (!empty($header['style']['fontStyle'])) {
                                $hStyle .= 'font-style: ' . $header['style']['fontStyle'] . ' !important;';
                            }
                        } else {
                            $hText = (string)$header;
                        }
                        
                        if (is_array($tplHeader)) {
                            if (isset($tplHeader['colspan']) && $tplHeader['colspan'] >= 0) $hColspan = $tplHeader['colspan'];
                            if (isset($tplHeader['rowspan']) && $tplHeader['rowspan'] >= 0) $hRowspan = $tplHeader['rowspan'];
                            if (!empty($tplHeader['style']['backgroundColor'])) {
                                $hStyle .= 'background-color: ' . $tplHeader['style']['backgroundColor'] . ' !important; background: ' . $tplHeader['style']['backgroundColor'] . ' !important;';
                            }
                            if (!empty($tplHeader['style']['color'])) {
                                $hStyle .= 'color: ' . $tplHeader['style']['color'] . ' !important;';
                            }
                            if (!empty($tplHeader['style']['fontFamily'])) {
                                $hStyle .= 'font-family: \'' . $tplHeader['style']['fontFamily'] . '\', sans-serif !important;';
                            }
                            if (!empty($tplHeader['style']['fontSize'])) {
                                $hStyle .= 'font-size: ' . $tplHeader['style']['fontSize'] . 'px !important;';
                            }
                            if (!empty($tplHeader['style']['fontWeight'])) {
                                $hStyle .= 'font-weight: ' . $tplHeader['style']['fontWeight'] . ' !important;';
                            }
                            if (!empty($tplHeader['style']['fontStyle'])) {
                                $hStyle .= 'font-style: ' . $tplHeader['style']['fontStyle'] . ' !important;';
                            }
                        }
                        
                        if (!empty($variables)) {
                            foreach ($variables as $vKey => $vVal) {
                                $hText = str_replace('{' . $vKey . '}', (string)$vVal, $hText);
                            }
                        }
                        
                        if ($hColspan > 0 && $hRowspan > 0) {
                            $hOutput = (strpos($hText, '<img') !== false || strpos($hText, '<div') !== false || strpos($hText, '<span') !== false) ? $hText : htmlspecialchars($hText);
                            $tableHtml .= '<th style="' . $cellBorder . ' padding: 6px; text-align: center; ' . $hStyle . '" colspan="' . $hColspan . '" rowspan="' . $hRowspan . '">' . $hOutput . '</th>';
                        }
                    }
                    $tableHtml .= '</tr></thead>';
                }
                
                if (!empty($displayRows)) {
                    $tableHtml .= '<tbody>';
                    foreach ($displayRows as $rIdx => $row) {
                        $tableHtml .= '<tr style="background: ' . $rowBg . ';">';
                        foreach ($row as $cIdx => $cell) {
                            if (isset($occupied[$rIdx][$cIdx]) && $occupied[$rIdx][$cIdx]) {
                                continue;
                            }
                            
                            $cText = '';
                            $cColspan = 1;
                            $cRowspan = 1;
                            $cStyle = '';
                            
                            $tplCell = $el['rows'][$rIdx][$cIdx] ?? null;
                            
                            if (is_array($cell)) {
                                $cText = $cell['text'] ?? '';
                                $cColspan = $cell['colspan'] ?? 1;
                                $cRowspan = $cell['rowspan'] ?? 1;
                                if (!empty($cell['style']['backgroundColor'])) {
                                    $cStyle .= 'background-color: ' . $cell['style']['backgroundColor'] . ' !important; background: ' . $cell['style']['backgroundColor'] . ' !important;';
                                }
                                if (!empty($cell['style']['color'])) {
                                    $cStyle .= 'color: ' . $cell['style']['color'] . ' !important;';
                                }
                                if (!empty($cell['style']['fontFamily'])) {
                                    $cStyle .= 'font-family: \'' . $cell['style']['fontFamily'] . '\', sans-serif !important;';
                                }
                                if (!empty($cell['style']['fontSize'])) {
                                    $cStyle .= 'font-size: ' . $cell['style']['fontSize'] . 'px !important;';
                                }
                                if (!empty($cell['style']['fontWeight'])) {
                                    $cStyle .= 'font-weight: ' . $cell['style']['fontWeight'] . ' !important;';
                                }
                                if (!empty($cell['style']['fontStyle'])) {
                                    $cStyle .= 'font-style: ' . $cell['style']['fontStyle'] . ' !important;';
                                }
                            } else {
                                $cText = (string)$cell;
                            }
                            
                            if (!empty($variables)) {
                                foreach ($variables as $vKey => $vVal) {
                                    $cText = str_replace('{' . $vKey . '}', (string)$vVal, $cText);
                                }
                            }
                            
                            if (is_array($tplCell)) {
                                if (isset($tplCell['colspan']) && $tplCell['colspan'] >= 0) $cColspan = $tplCell['colspan'];
                                if (isset($tplCell['rowspan']) && $tplCell['rowspan'] >= 0) $cRowspan = $tplCell['rowspan'];
                                if (!empty($tplCell['style']['backgroundColor'])) {
                                    $cStyle .= 'background-color: ' . $tplCell['style']['backgroundColor'] . ' !important; background: ' . $tplCell['style']['backgroundColor'] . ' !important;';
                                }
                                if (!empty($tplCell['style']['color'])) {
                                    $cStyle .= 'color: ' . $tplCell['style']['color'] . ' !important;';
                                }
                                if (!empty($tplCell['style']['fontFamily'])) {
                                    $cStyle .= 'font-family: \'' . $tplCell['style']['fontFamily'] . '\', sans-serif !important;';
                                }
                                if (!empty($tplCell['style']['fontSize'])) {
                                    $cStyle .= 'font-size: ' . $tplCell['style']['fontSize'] . 'px !important;';
                                }
                                if (!empty($tplCell['style']['fontWeight'])) {
                                    $cStyle .= 'font-weight: ' . $tplCell['style']['fontWeight'] . ' !important;';
                                }
                                if (!empty($tplCell['style']['fontStyle'])) {
                                    $cStyle .= 'font-style: ' . $tplCell['style']['fontStyle'] . ' !important;';
                                }
                            }
                            
                            if ($cColspan > 0 && $cRowspan > 0) {
                                for ($i = 0; $i < $cRowspan; $i++) {
                                    for ($j = 0; $j < $cColspan; $j++) {
                                        if (($rIdx + $i) < $R && ($cIdx + $j) < $C) {
                                            if ($i > 0 || $j > 0) {
                                                $occupied[$rIdx + $i][$cIdx + $j] = true;
                                            }
                                        }
                                    }
                                }
                                $cOutput = (strpos($cText, '<img') !== false || strpos($cText, '<div') !== false || strpos($cText, '<span') !== false) ? $cText : htmlspecialchars($cText);
                                $tableHtml .= '<td style="' . $cellBorder . ' padding: 6px; text-align: center; ' . $cStyle . '" colspan="' . $cColspan . '" rowspan="' . $cRowspan . '">' . $cOutput . '</td>';
                            }
                        }
                        $tableHtml .= '</tr>';
                    }
                    $tableHtml .= '</tbody>';
                }
                return $tableHtml;
            };

            // Build table HTML
            $tableHtml = '';
            if ($documentType === 'start_sheet' && !empty($tableData['results'])) {
                // Group results by date
                $byDate = [];
                foreach ($tableData['results'] as $res) {
                    $d = $res['scheduled_date'] ?: date('Y-m-d');
                    $byDate[$d][] = $res;
                }
                ksort($byDate);

                // Fetch all distinct scheduled_dates across lane_allocations to compute true Championship Day numbers
                $dateToDayMap = [];
                try {
                    $allCompDates = $pdo->query("SELECT DISTINCT scheduled_date FROM lane_allocations WHERE scheduled_date IS NOT NULL AND scheduled_date != '' ORDER BY scheduled_date ASC")->fetchAll(PDO::FETCH_COLUMN);
                    $dIdx = 1;
                    foreach ($allCompDates as $cd) {
                        $dateToDayMap[$cd] = $dIdx++;
                    }
                } catch (Exception $e) {}
                
                foreach ($byDate as $dVal => $dResults) {
                    $formattedDate = date('d/m/Y', strtotime($dVal));
                    $dayNum = $dateToDayMap[$dVal] ?? 1;
                    $tableHtml .= '<div style="margin-top: 15px; margin-bottom: 8px; font-family: ' . $fontFamily . ', sans-serif; font-size: ' . ($fontSize + 2) . 'px; font-weight: bold; color: ' . $color . '; border-bottom: 1px solid ' . $bColor . '; padding-bottom: 4px;">CHAMPIONSHIP DAY ' . $dayNum . ' - ' . $formattedDate . '</div>';
                    
                    $tableHtml .= '<table style="width: 100%; border-collapse: collapse; font-family: ' . $fontFamily . ', sans-serif; font-size: ' . $fontSize . 'px; color: ' . $color . '; background: ' . $bgColor . '; ' . $tableBorder . '; margin-bottom: 25px;">';
                    
                    $displayRows = mapResultsToHeaders($dResults, $displayHeaders, $documentType);
                    $tableHtml .= $renderTableHtml($displayHeaders, $displayRows, $el, $cellBorder, $headerBg, $headerTextColor, $rowBg);
                    
                    $tableHtml .= '</table>';
                }
            } else {
                $tableHtml = '<table style="width: 100%; border-collapse: collapse; font-family: ' . $fontFamily . ', sans-serif; font-size: ' . $fontSize . 'px; color: ' . $color . '; height: 100%; background: ' . $bgColor . '; ' . $tableBorder . '">';
                
                if (($documentType === 'score_sheet' || strpos($documentType, 'score_sheet_') === 0 || $documentType === 'start_sheet') && !empty($el['rows'])) {
                    $displayHeaders = $el['headers'] ?? [];
                    $displayRows = $el['rows'] ?? [];
                    
                    if (strpos($documentType, 'score_sheet_') === 0 && !empty($tableData['rows']) && count($tableData['rows']) !== count($displayRows)) {
                        $displayHeaders = [];
                        foreach ($tableData['headers'] as $h) {
                            $displayHeaders[] = ['text' => $h, 'colspan' => 1, 'rowspan' => 1];
                        }
                        $displayRows = [];
                        foreach ($tableData['rows'] as $rIdx => $r) {
                            $newRow = [];
                            $totalColsInRow = count($r);
                            $isSummaryRow = ($totalColsInRow === 12 && $r[1] === '' && $r[10] === '');
                            if ($isSummaryRow) {
                                $newRow[] = [
                                    'text' => $r[0],
                                    'colspan' => 11,
                                    'rowspan' => 1,
                                    'style' => ['fontWeight' => 'bold']
                                ];
                                $newRow[] = [
                                    'text' => $r[11],
                                    'colspan' => 1,
                                    'rowspan' => 1,
                                    'style' => ['fontWeight' => 'bold']
                                ];
                            } else {
                                foreach ($r as $cIdx => $val) {
                                    $newRow[] = [
                                        'text' => $val,
                                        'colspan' => 1,
                                        'rowspan' => 1,
                                        'style' => []
                                    ];
                                }
                            }
                            $displayRows[] = $newRow;
                        }
                    }
                    
                    if (!empty($tableData['rows'])) {
                        foreach ($displayRows as $rIdx => &$row) {
                            if (isset($tableData['rows'][$rIdx])) {
                                $gridCol = 0;
                                foreach ($row as &$cell) {
                                    $cColspan = is_array($cell) ? ($cell['colspan'] ?? 1) : 1;
                                    if (is_array($cell)) {
                                        $txt = trim((string)($cell['text'] ?? ''));
                                        if ($txt === '' || $txt === 'Sample' || $txt === '—' || $txt === '0' || $txt === '-') {
                                            $foundVal = null;
                                            for ($k = $gridCol; $k < $gridCol + $cColspan; $k++) {
                                                if (isset($tableData['rows'][$rIdx][$k]) && trim((string)$tableData['rows'][$rIdx][$k]) !== '') {
                                                    $foundVal = $tableData['rows'][$rIdx][$k];
                                                }
                                            }
                                            if ($foundVal === null) {
                                                $lastColIdx = $gridCol + $cColspan - 1;
                                                $foundVal = $tableData['rows'][$rIdx][$lastColIdx] ?? '';
                                            }
                                            $cell['text'] = $foundVal;
                                        }
                                    }
                                    $gridCol += $cColspan;
                                }
                            }
                        }
                    }
                    
                    if (!empty($tableData['headers'])) {
                        foreach ($displayHeaders as $cIdx => &$header) {
                            if (is_array($header)) {
                                $txt = trim((string)($header['text'] ?? ''));
                                if ($txt === '' || $txt === 'Sample' || $txt === 'Col' || strpos($txt, 'Col ') === 0) {
                                    if (isset($tableData['headers'][$cIdx])) {
                                        $header['text'] = $tableData['headers'][$cIdx];
                                    }
                                }
                            }
                        }
                    }
                } else {
                    if (!empty($tableData['results']) && !empty($displayHeaders)) {
                        $displayRows = mapResultsToHeaders($tableData['results'], $displayHeaders, $documentType);
                    } else {
                        $displayRows = !empty($tableData['rows']) ? $tableData['rows'] : ($el['rows'] ?? []);
                    }
                }
                
                $tableHtml .= $renderTableHtml($displayHeaders, $displayRows, $el, $cellBorder, $headerBg, $headerTextColor, $rowBg);
                
                $tableHtml .= '</table>';
            }
            
            $html .= '<div style="' . $style . ' background: ' . $bgColor . ';">' . $tableHtml . '</div>';
        }
    }
    
    $html .= '</div>';
    return $html;
}

function getParticipantApprovedResults(PDO $pdo, int $userId): array {
    global $EVENTS_MAPPING;

    // 1. Fetch approved event registrations for the user
    $approvedEventsStmt = $pdo->prepare(
        "SELECT er.id AS event_reg_db_id, er.event_reg_id, er.category, er.event_name, er.event_code, er.competition_name, er.mqs_score, er.match_no,
                ss_direct.grand_total_val AS direct_grand_total,
                ss_direct.remarks AS direct_remarks
         FROM event_registrations er
         LEFT JOIN lane_allocations la_direct ON la_direct.event_reg_id = er.id
         LEFT JOIN score_sheets ss_direct ON ss_direct.lane_alloc_id = la_direct.id
         WHERE er.user_id = ? AND er.status = 'approved' 
         ORDER BY er.id ASC"
    );
    $approvedEventsStmt->execute([$userId]);
    $approvedEvents = $approvedEventsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all scored allocations for this user to use as fallback
    $userScoresStmt = $pdo->prepare(
        "SELECT er_inner.event_name, er_inner.category, ss_inner.grand_total_val, ss_inner.remarks
         FROM lane_allocations la_inner
         JOIN event_registrations er_inner ON la_inner.event_reg_id = er_inner.id
         JOIN score_sheets ss_inner ON la_inner.id = ss_inner.lane_alloc_id
         WHERE er_inner.user_id = ? AND ss_inner.grand_total_val IS NOT NULL AND ss_inner.grand_total_val != ''"
    );
    $userScoresStmt->execute([$userId]);
    $userScoredAllocations = $userScoresStmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    foreach ($approvedEvents as $evt) {
        $evtName = $evt['event_name'];
        $cat = $evt['category'];
        
        $evtScore = $evt['direct_grand_total'] ?? null;
        $remarks = $evt['direct_remarks'] ?? null;

        if (empty($evtScore)) {
            $evtFullName = $EVENTS_MAPPING[$evtName] ?? $evtName;
            $evtBaseType = getBackendEventBaseType($evtFullName);
            $evtCatFamily = (strpos(strtoupper($cat), 'ISSF') !== false || strpos(strtoupper($evtFullName), 'ISSF') !== false) ? 'ISSF' : 'NR';

            foreach ($userScoredAllocations as $sa) {
                $saFullName = $EVENTS_MAPPING[$sa['event_name']] ?? $sa['event_name'];
                $saBaseType = getBackendEventBaseType($saFullName);
                $saCatFamily = (strpos(strtoupper($sa['category']), 'ISSF') !== false || strpos(strtoupper($saFullName), 'ISSF') !== false) ? 'ISSF' : 'NR';

                if ($evtCatFamily === $saCatFamily && $evtBaseType === $saBaseType) {
                    $evtScore = $sa['grand_total_val'];
                    $remarks = $sa['remarks'];
                    break;
                }
            }
        }

        if (empty($evtScore)) {
            continue;
        }

        if (!empty($remarks) && !in_array(strtoupper($remarks), ['', 'C', 'COMPLETED'], true)) {
            continue;
        }
        
        // Calculate dynamic rank for this event
        $sqlRank = "
            SELECT er.user_id, er.event_name, er.category,
                   ss_direct.grand_total_val AS direct_grand_total,
                   ss_direct.series_data AS direct_series_data,
                   ss_direct.remarks AS direct_remarks
            FROM event_registrations er
            LEFT JOIN lane_allocations la_direct ON la_direct.event_reg_id = er.id
            LEFT JOIN score_sheets ss_direct ON ss_direct.lane_alloc_id = la_direct.id
            WHERE er.event_name = ? AND UPPER(er.category) = UPPER(?) AND er.status = 'approved'
        ";
        $qRank = $pdo->prepare($sqlRank);
        $qRank->execute([$evtName, $cat]);
        $allCompetitors = $qRank->fetchAll(PDO::FETCH_ASSOC);

        $evtFullName = $EVENTS_MAPPING[$evtName] ?? $evtName;
        $evtBaseType = getBackendEventBaseType($evtFullName);
        $evtCatFamily = (strpos(strtoupper($cat), 'ISSF') !== false || strpos(strtoupper($evtFullName), 'ISSF') !== false) ? 'ISSF' : 'NR';

        $competitorRows = [];
        $seenUserIds = [];

        foreach ($allCompetitors as $comp) {
            $cId = (int)$comp['user_id'];
            if (isset($seenUserIds[$cId])) continue;

            $cScore = $comp['direct_grand_total'] ?? null;
            $cSeriesData = $comp['direct_series_data'] ?? null;
            $cRemarks = $comp['direct_remarks'] ?? null;

            if (empty($cScore)) {
                $cScoresStmt = $pdo->prepare(
                    "SELECT er_inner.event_name, er_inner.category, ss_inner.grand_total_val, ss_inner.series_data, ss_inner.remarks
                     FROM lane_allocations la_inner
                     JOIN event_registrations er_inner ON la_inner.event_reg_id = er_inner.id
                     JOIN score_sheets ss_inner ON la_inner.id = ss_inner.lane_alloc_id
                     WHERE er_inner.user_id = ? AND ss_inner.grand_total_val IS NOT NULL AND ss_inner.grand_total_val != ''"
                );
                $cScoresStmt->execute([$cId]);
                $cScoredAllocs = $cScoresStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($cScoredAllocs as $sa) {
                    $saFullName = $EVENTS_MAPPING[$sa['event_name']] ?? $sa['event_name'];
                    $saBaseType = getBackendEventBaseType($saFullName);
                    $saCatFamily = (strpos(strtoupper($sa['category']), 'ISSF') !== false || strpos(strtoupper($saFullName), 'ISSF') !== false) ? 'ISSF' : 'NR';

                    if ($evtCatFamily === $saCatFamily && $evtBaseType === $saBaseType) {
                        $cScore = $sa['grand_total_val'];
                        $cSeriesData = $sa['series_data'];
                        $cRemarks = $sa['remarks'];
                        break;
                    }
                }
            }

            if (!empty($cScore)) {
                $seenUserIds[$cId] = true;
                $competitorRows[] = [
                    'user_id' => $cId,
                    'grand_total_val' => $cScore,
                    'series_data' => $cSeriesData,
                    'remarks' => $cRemarks
                ];
            }
        }

        usort($competitorRows, 'compareShooterScores');
        $rankCount = 0;
        $evtRank = '1';
        foreach ($competitorRows as $compRow) {
            $rankCount++;
            if ((int)$compRow['user_id'] === $userId) {
                $evtRank = (string)$rankCount;
                break;
            }
        }
        
        if ($evtScore !== '') {
            $resolvedCode = resolveEventCode($evtName, $evt['event_code'] ?? null, $evt['match_no'] ?? null);
            $cleanCode = str_replace('-', '', $resolvedCode);
            
            $mqsVal = null;
            if (!empty($evt['mqs_score']) && (float)$evt['mqs_score'] > 0) {
                $mqsVal = (float)$evt['mqs_score'];
            } else {
                $mqsStmt = $pdo->prepare("
                    SELECT mqs_score 
                    FROM events 
                    WHERE mqs_score IS NOT NULL AND mqs_score > 0 AND (
                        event_code = ? OR event_code = ? OR event_name = ?
                    )
                    LIMIT 1
                ");
                $mqsStmt->execute([$resolvedCode, $cleanCode, $evtName]);
                $foundMqs = $mqsStmt->fetchColumn();
                if ($foundMqs !== false && (float)$foundMqs > 0) {
                    $mqsVal = (float)$foundMqs;
                }
            }

            $results[] = [
                'is_team'    => false,
                'event_name' => $evtName,
                'category'   => $cat,
                'mqs'        => ($mqsVal !== null) ? number_format($mqsVal, 2) : '—',
                'score'      => $evtScore,
                'rank'       => $evtRank,
                'total'      => count($competitorRows),
                'match_no'   => $resolvedCode
            ];
        }
    }

    // 2. Fetch team results for the competitor
    $userRegStmt = $pdo->prepare("SELECT id, reg_id, first_name, last_name FROM registrations WHERE id = ? LIMIT 1");
    $userRegStmt->execute([$userId]);
    $userRow = $userRegStmt->fetch(PDO::FETCH_ASSOC);

    $userRegId = $userRow['reg_id'] ?? '';
    $userBib = function_exists('formatBibNo') ? formatBibNo($userRegId) : '';
    $userFullName = trim(($userRow['first_name'] ?? '') . ' ' . ($userRow['last_name'] ?? ''));

    $teamStmt = $pdo->prepare("
        SELECT DISTINCT t.id AS team_id, t.team_name, t.event_name, t.category
        FROM team_members tm
        JOIN teams t ON tm.team_id = t.id
        LEFT JOIN registrations r ON tm.enrollment_id = r.reg_id OR tm.enrollment_id = r.id
        WHERE r.id = ? OR tm.enrollment_id = ? OR tm.enrollment_id = ?
           OR (tm.shooter_name IS NOT NULL AND tm.shooter_name != '' AND UPPER(tm.shooter_name) = UPPER(?))
    ");
    $teamStmt->execute([$userId, $userRegId, $userBib, $userFullName]);
    $userTeams = $teamStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($userTeams as $team) {
        $tid = (int)$team['team_id'];
        $evtName = $team['event_name'];
        $cat = $team['category'];
        
        // Fetch all teams for this event and category to calculate ranks accurately
        $allTeamsStmt = $pdo->prepare("
            SELECT t.id, t.team_name, t.total_score AS stored_team_total
            FROM teams t
            WHERE t.event_name = ? AND (UPPER(t.category) = UPPER(?) OR t.category IS NULL OR t.category = '')
        ");
        $allTeamsStmt->execute([$evtName, $cat]);
        $allTeamsList = $allTeamsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $teamScores = [];
        foreach ($allTeamsList as $tItem) {
            $tIdItem = (int)$tItem['id'];
            
            // Fetch team members for this team
            $mStmt = $pdo->prepare("
                SELECT tm.enrollment_id, tm.shooter_name, tm.score AS stored_score, tm.lane_alloc_id
                FROM team_members tm
                WHERE tm.team_id = ?
            ");
            $mStmt->execute([$tIdItem]);
            $members = $mStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $calculatedTeamTotal = 0.0;
            foreach ($members as $m) {
                $scoreInfo = resolveTeamMemberScore(
                    $pdo,
                    (string)$m['enrollment_id'],
                    $evtName,
                    $cat,
                    !empty($m['lane_alloc_id']) ? (int)$m['lane_alloc_id'] : null,
                    (string)$m['shooter_name']
                );
                
                $liveScore = (float)$scoreInfo['score'];
                if ($liveScore <= 0 && !empty($m['stored_score'])) {
                    $liveScore = (float)$m['stored_score'];
                }
                $calculatedTeamTotal += $liveScore;
            }
            
            if ($calculatedTeamTotal <= 0 && !empty($tItem['stored_team_total'])) {
                $calculatedTeamTotal = (float)$tItem['stored_team_total'];
            }
            
            $teamScores[] = [
                'team_id'     => $tIdItem,
                'team_name'   => $tItem['team_name'],
                'total_score' => $calculatedTeamTotal
            ];
        }
        
        // Sort teams descending by calculated total score
        usort($teamScores, fn($a, $b) => $b['total_score'] <=> $a['total_score']);
        
        $teamRank = 0;
        $teamScore = '0.00';
        foreach ($teamScores as $idx => $tObj) {
            if ($tObj['team_id'] === $tid) {
                $teamRank = $idx + 1;
                $teamScore = number_format($tObj['total_score'], 2, '.', '');
                break;
            }
        }
        
        if ($teamRank > 0 && (float)$teamScore > 0) {
            $results[] = [
                'is_team'    => true,
                'event_name' => $evtName,
                'category'   => $cat,
                'mqs'        => '—', 
                'score'      => $teamScore,
                'rank'       => (string)$teamRank,
                'total'      => count($teamScores),
                'team_name'  => $team['team_name'],
                'match_no'   => 'Team'
            ];
        }
    }

    return $results;
}

function getCleanEventLabel(string $rawLabel, string $category = ''): string {
    $clean = $rawLabel;
    
    // Standard suffix mapping
    $suffix = '';
    $catUpper = strtoupper(trim($category));
    if ($catUpper === 'ISSF') {
        $suffix = ' (ISSF)';
    } elseif ($catUpper === 'NR' || $catUpper === 'NR_MQS') {
        $suffix = ' (NR)';
    } elseif ($catUpper === 'PARA_DEAF') {
        $suffix = ' (PARA/DEAF)';
    }
    
    // Remove brackets with standard categories from raw to prevent duplicate appending
    $clean = preg_replace('/\((ISSF|NR|NR_MQS|PARA_DEAF)\)/i', '', $clean);
    
    // Words to remove as full words
    $wordsToRemove = [
        'CHAMPIONSHIP', 'COMPETITION', 'SUPER MASTER', 'SENIOR MASTER', 'MASTER', 
        'SENIOR', 'JUNIOR', 'SUB JUNIOR', 'YOUTH', 'SUB YOUTH', 'CADET', 'CIVILIAN',
        'OPEN', 'INDIVIDUAL', 'INDIVIDUAL.', 'TEAM', 'MEN', 'WOMEN', 'MAN', 'WOMAN'
    ];
    
    foreach ($wordsToRemove as $word) {
        $clean = preg_replace('/\b' . preg_quote($word, '/') . '\b/i', '', $clean);
    }
    
    $clean = preg_replace('/\s+/', ' ', $clean);
    $clean = trim($clean);
    
    // Append standard category suffix if not already present
    if ($suffix !== '' && strpos(strtoupper($clean), strtoupper(trim($suffix))) === false) {
        $clean .= $suffix;
    }
    
    return $clean;
}

function mapResultsToHeaders(array $results, array $headers, string $documentType = ''): array {
    global $EVENTS_MAPPING;
    if (!is_array($EVENTS_MAPPING)) {
        $EVENTS_MAPPING = [];
    }
    $rows = [];
    $sr = 1;
    
    $toRoman = function(int $num): string {
        $map = [
            1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V',
            6 => 'VI', 7 => 'VII', 8 => 'VIII', 9 => 'IX', 10 => 'X'
        ];
        return $map[$num] ?? (string)$num;
    };

    foreach ($results as $res) {
        $row = [];
        
        if ($documentType === 'print_summary') {
            foreach ($headers as $header) {
                $hText  = is_array($header) ? ($header['text'] ?? '') : (string)$header;
                $hClean = strtolower(trim((string)$hText));
                if (strpos($hClean, 's.no') !== false || strpos($hClean, 'sr') !== false || $hClean === 'no') {
                    $row[] = $res['sr'] ?? $sr;
                } elseif (strpos($hClean, 'club') !== false || strpos($hClean, 'district') !== false || strpos($hClean, 'assoc') !== false) {
                    $row[] = $res['club_name'] ?: ($res['district'] ?? '—');
                } elseif (strpos($hClean, 'date') !== false) {
                    $row[] = $res['scheduled_date'] ?? '';
                } elseif (strpos($hClean, 'relay') !== false || strpos($hClean, 'rly') !== false) {
                    $row[] = $res['relay_no'] ?? '—';
                } elseif (strpos($hClean, 'fp') !== false || strpos($hClean, 'lane') !== false || strpos($hClean, 'point') !== false) {
                    $row[] = $res['lane_no'] ?? '—';
                } elseif (strpos($hClean, 'name') !== false || strpos($hClean, 'shooter') !== false || strpos($hClean, 'athlete') !== false) {
                    $row[] = $res['first_name'] ?? '—';
                } elseif (strpos($hClean, 'enroll') !== false || strpos($hClean, 'bib') !== false || strpos($hClean, 'id') !== false) {
                    $row[] = $res['bib_no'] ?? ($res['reg_id'] ?? '—');
                } else {
                    $row[] = '—';
                }
            }
            $rows[] = $row;
            $sr++;
            continue;
        }

        if ($documentType === 'start_sheet') {
            foreach ($headers as $header) {
                $hText = is_array($header) ? ($header['text'] ?? '') : (string)$header;
                $hClean = strtolower(trim((string)$hText));
                
                if (strpos($hClean, 'st time') !== false || strpos($hClean, 'start') !== false || $hClean === 'time') {
                    $row[] = !empty($res['start_time']) ? (string)$res['start_time'] : '—';
                } elseif (strpos($hClean, 'rly') !== false || strpos($hClean, 'relay') !== false) {
                    $row[] = !empty($res['relay_no']) ? (string)$res['relay_no'] : '—';
                } elseif (strpos($hClean, 'fp') !== false || strpos($hClean, 'lane') !== false || strpos($hClean, 'point') !== false) {
                    $row[] = (!empty($res['lane_no']) && (int)$res['lane_no'] > 0) ? (string)$res['lane_no'] : '—';
                } elseif (strpos($hClean, 'target') !== false || strpos($hClean, 'serial') !== false) {
                    $row[] = !empty($res['target_serial_no']) ? (string)$res['target_serial_no'] : '—';
                } elseif (strpos($hClean, 'bib') !== false || strpos($hClean, 'enroll') !== false || strpos($hClean, 'id') !== false) {
                    $rawEnroll = !empty($res['bib_no']) ? $res['bib_no'] : (!empty($res['enrollment_id']) ? $res['enrollment_id'] : (!empty($res['reg_id']) ? formatBibNo($res['reg_id']) : '—'));
                    if (stripos((string)$rawEnroll, 'MANUAL') !== false || strtoupper((string)$rawEnroll) === 'TBD') {
                        $row[] = '—';
                    } else {
                        $row[] = $rawEnroll;
                    }
                } elseif (strpos($hClean, 'club') !== false || strpos($hClean, 'team') !== false) {
                    $row[] = !empty($res['club_name']) ? $res['club_name'] : '—';
                } elseif (strpos($hClean, 'name') !== false || strpos($hClean, 'shooter') !== false || strpos($hClean, 'athlete') !== false) {
                    $fullName = trim(($res['first_name'] ?? '') . ' ' . ($res['last_name'] ?? ''));
                    $displayName = (!empty($res['custom_name'])) ? $res['custom_name'] : ((stripos($fullName, 'MANUAL SHOOTER') !== false) ? '' : $fullName);
                    $row[] = !empty($displayName) ? $displayName : '—';
                } else {
                    $row[] = '—';
                }
            }
            $rows[] = $row;
            $sr++;
            continue;
        }
        
        if ($documentType === 'rank_list') {
            foreach ($headers as $header) {
                $hText = is_array($header) ? ($header['text'] ?? '') : (string)$header;
                $hClean = strtolower(trim((string)$hText));
                
                if (strpos($hClean, 'rank') !== false || strpos($hClean, 'pos') !== false) {
                    $row[] = (string)$sr;
                } elseif (strpos($hClean, 'lane') !== false && strpos($hClean, 'relay') === false) {
                    $row[] = !empty($res['lane_no']) ? (string)$res['lane_no'] : (string)$sr;
                } elseif (strpos($hClean, 'club') !== false || strpos($hClean, 'association') !== false) {
                    $cName = $res['club_name'] ?? '—';
                    $row[] = (stripos($cName, 'MANUAL') !== false) ? '—' : $cName;
                } elseif (strpos($hClean, 'competitor') !== false || strpos($hClean, 'name') !== false || strpos($hClean, 'shooter') !== false) {
                    $fullName = trim(($res['first_name'] ?? '') . ' ' . ($res['last_name'] ?? ''));
                    $row[] = (stripos($fullName, 'MANUAL SHOOTER') !== false) ? '' : $fullName;
                } elseif (strpos($hClean, 'enrollment') !== false || strpos($hClean, 'bib') !== false || strpos($hClean, 'id') !== false || strpos($hClean, 'reg') !== false) {
                    $rawEnroll = $res['bib_no'] ?: ($res['reg_id'] ?? '');
                    if (stripos($rawEnroll, 'MANUAL') !== false || strtoupper($rawEnroll) === 'TBD') {
                        $row[] = '';
                    } else {
                        $row[] = $rawEnroll;
                    }
                } elseif (strpos($hClean, 'district') !== false) {
                    $row[] = $res['district'] ?? '—';
                } elseif (strpos($hClean, 'date') !== false) {
                    $row[] = !empty($res['scheduled_date']) ? date('d M Y', strtotime($res['scheduled_date'])) : date('d M Y');
                } elseif (strpos($hClean, 'relay') !== false) {
                    $relay = $res['relay_no'] ?? '—';
                    $lane = $res['lane_no'] ?? '—';
                    $row[] = "R{$relay} / L{$lane}";
                } elseif (strpos($hClean, 'score') !== false || strpos($hClean, 'total') !== false) {
                    $row[] = $res['grand_total_val'] ?? '0';
                } else {
                    $row[] = '—';
                }
            }
            $rows[] = $row;
            $sr++;
            continue;
        }

        // Map dynamic event label
        $baseLabel = $EVENTS_MAPPING[$res['event_name']] ?? $res['event_name'];
        if ($documentType === 'competitor_card') {
            // Determine weapon type
            $weapon = 'RIFLE';
            if (isset($res['weapon_type']) && !empty($res['weapon_type'])) {
                if (stripos($res['weapon_type'], 'pistol') !== false) {
                    $weapon = 'PISTOL';
                } elseif (stripos($res['weapon_type'], 'rifle') !== false) {
                    $weapon = 'RIFLE';
                } else {
                    $weapon = strtoupper(trim($res['weapon_type']));
                }
            } else {
                if (stripos($baseLabel, 'pistol') !== false) {
                    $weapon = 'PISTOL';
                } elseif (stripos($baseLabel, 'rifle') !== false) {
                    $weapon = 'RIFLE';
                }
            }

            // Determine category
            $cat = 'NR';
            if (isset($res['category']) && !empty($res['category'])) {
                $c = strtoupper(trim((string)$res['category']));
                if (strpos($c, 'ISSF') !== false) {
                    $cat = 'ISSF';
                } elseif (strpos($c, 'NR') !== false) {
                    $cat = 'NR';
                } else {
                    $cat = $c;
                }
            }
            // Avoid duplicate weapon name and category in the display label
            $prefixParts = [];
            if (stripos($baseLabel, $weapon) === false) {
                $prefixParts[] = $weapon;
            }
            if (stripos($baseLabel, $cat) === false) {
                $prefixParts[] = $cat;
            }
            
            if (!empty($prefixParts)) {
                $eventLabel = implode(' - ', $prefixParts) . ' - ' . $baseLabel;
            } else {
                $eventLabel = $baseLabel;
            }
        } else {
            $eventLabel = $baseLabel;
        }
        if ($res['is_team']) {
            $eventLabel .= ' (Team)';
        }

        // Position string
        $posStr = '—';
        $rankVal = isset($res['rank']) ? (int)$res['rank'] : 0;
        $totalVal = isset($res['total']) ? (int)$res['total'] : 0;
        if ($rankVal > 0 && $totalVal > 0) {
            if (!empty($res['is_team'])) {
                $posStr = $toRoman($rankVal) . '/' . $totalVal;
            } else {
                $posStr = $rankVal . '/' . $totalVal;
            }
        }

        // Remarks / Medals
        $medal = '---';
        if ($rankVal === 1) {
            $medal = 'GOLD';
        } elseif ($rankVal === 2) {
            $medal = 'SILVER';
        } elseif ($rankVal === 3) {
            $medal = 'BRONZE';
        }

        foreach ($headers as $header) {
            $hText = is_array($header) ? ($header['text'] ?? '') : (string)$header;
            $hClean = strtolower(trim((string)$hText));
            
            if (strpos($hClean, 's.no') !== false || strpos($hClean, 'sr') !== false || strpos($hClean, 'serial') !== false) {
                $row[] = (string)$sr;
            } elseif (strpos($hClean, 'no') !== false || strpos($hClean, 'code') !== false || strpos($hClean, 'num') !== false) {
                $evtCodeVal = !empty($res['match_no']) ? $res['match_no'] : (!empty($res['event_code']) ? $res['event_code'] : (!empty($res['event_no']) ? $res['event_no'] : resolveEventCode($res['event_name'] ?? '')));
                $row[] = !empty($evtCodeVal) ? $evtCodeVal : '—';
            } elseif (strpos($hClean, 'event') !== false || strpos($hClean, 'comp') !== false || strpos($hClean, 'name') !== false) {
                $row[] = $eventLabel;
            } elseif (strpos($hClean, 'mqs') !== false) {
                $row[] = $res['mqs'] ?: '';
            } elseif (strpos($hClean, 'score') !== false || strpos($hClean, 'total') !== false) {
                $row[] = $res['score'];
            } elseif (strpos($hClean, 'pos') !== false || strpos($hClean, 'rank') !== false) {
                $row[] = $posStr;
            } elseif (strpos($hClean, 'remark') !== false || strpos($hClean, 'medal') !== false || strpos($hClean, 'award') !== false) {
                $row[] = $medal;
            } else {
                $row[] = '—';
            }
        }
        $rows[] = $row;
        $sr++;
    }
    return $rows;
}
