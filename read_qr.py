import sys
import importlib

# Dynamic import to satisfy static IDE linter if opencv-python is not installed
try:
    cv2 = importlib.import_module('cv2')
except ImportError:
    cv2 = None

if len(sys.argv) > 1 and sys.argv[1]:
    img_path = sys.argv[1]
    if cv2 is not None:
        img = cv2.imread(img_path)
        if img is not None:
            detector = cv2.QRCodeDetector()
            data, bbox, straight_qrcode = detector.detectAndDecode(img)
            if data:
                print(f"QR Data: {data}")
            else:
                print("No QR code found")
        else:
            print("Could not load image file.")
    else:
        print("OpenCV (cv2) is not installed. Install it with: pip install opencv-python")
else:
    print("Usage: python read_qr.py <image_path>")
