<?php
// admin/actions/download_registration_template.php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
checkAdminAuth();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="registration_import_template.csv"');

$output = fopen('php://output', 'w');

// UTF-8 BOM for Excel compatibility
fputs($output, "\xEF\xBB\xBF");

// Comprehensive Column Headers covering Registrations, Event Registrations, and Lane Allocations
fputcsv($output, [
    'Reg ID',
    'First Name',
    'Last Name',
    'Email',
    'Phone',
    'Aadhaar Number',
    'Club Name',
    'District',
    'State Association',
    'Father Name',
    'Address',
    'DOB',
    'Gender',
    'Event Name',
    'Category',
    'Weapon Type',
    'Age Group',
    'Match No',
    'Relay No',
    'Lane No',
    'Scheduled Date',
    'Start Time',
    'Target Serial No'
]);

// Sample Row 1
fputcsv($output, [
    'SSA-9001',
    'Ramesh',
    'Kumar',
    'ramesh.k@example.com',
    '9876543210',
    '123456789012',
    'Chennai Rifle Club',
    'Chennai',
    'SSA',
    'S. Kumar',
    '123 Main St, Chennai',
    '1995-05-15',
    'Male',
    '10m Air Rifle Men',
    'NR',
    'Air Rifle',
    'Senior',
    'M-101',
    '1',
    '5',
    '2026-08-10',
    '09:00:00',
    'TGT-001'
]);

// Sample Row 2
fputcsv($output, [
    'SSA-9002',
    'Priya',
    'Sharma',
    'priya.s@example.com',
    '9876543211',
    '987654321098',
    'Coimbatore Shooting Academy',
    'Coimbatore',
    'SSA',
    'A. Sharma',
    '45 Park Road, Coimbatore',
    '2002-08-20',
    'Female',
    '10m Air Pistol Women',
    'ISSF',
    'Air Pistol',
    'Junior',
    'M-102',
    '1',
    '6',
    '2026-08-10',
    '09:00:00',
    'TGT-002'
]);

fclose($output);
exit;
