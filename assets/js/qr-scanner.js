// assets/js/qr-scanner.js - Fixed version with jsQR

class QRScanner {
    constructor(videoElement, callback) {
        this.video = videoElement;
        this.callback = callback;
        this.scanning = false;
        this.canvas = document.createElement('canvas');
        this.context = this.canvas.getContext('2d');
        this.scanInterval = null;
    }
    
    // Start scanning
    async start() {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ 
                video: { facingMode: 'environment' } // Prefer rear camera
            });
            
            this.video.srcObject = stream;
            this.video.setAttribute('playsinline', true);
            this.video.play();
            
            this.scanning = true;
            this.scanInterval = requestAnimationFrame(() => this.scan());
        } catch (error) {
            console.error('Camera access failed:', error);
            throw new Error('Could not access camera. Make sure you have granted camera permissions.');
        }
    }
    
    // Stop scanning
    stop() {
        this.scanning = false;
        if (this.scanInterval) {
            cancelAnimationFrame(this.scanInterval);
        }
        if (this.video.srcObject) {
            this.video.srcObject.getTracks().forEach(track => track.stop());
            this.video.srcObject = null;
        }
    }
    
    // Scan loop using jsQR
    scan() {
        if (!this.scanning) return;
        
        if (this.video.readyState === this.video.HAVE_ENOUGH_DATA) {
            this.canvas.width = this.video.videoWidth;
            this.canvas.height = this.video.videoHeight;
            
            this.context.drawImage(
                this.video, 
                0, 0, 
                this.canvas.width, 
                this.canvas.height
            );
            
            const imageData = this.context.getImageData(
                0, 0, 
                this.canvas.width, 
                this.canvas.height
            );
            
            // Use jsQR to detect QR codes
            const code = jsQR(imageData.data, imageData.width, imageData.height, {
                inversionAttempts: "dontInvert"
            });
            
            if (code) {
                console.log('QR Code detected:', code.data);
                this.callback(code.data);
                this.stop();
                return;
            }
        }
        
        this.scanInterval = requestAnimationFrame(() => this.scan());
    }
}

// Initialize scanner
function initQRScanner(buttonId, videoId, resultCallback) {
    const button = document.getElementById(buttonId);
    const video = document.getElementById(videoId);
    
    if (!button || !video) return;
    
    let scanner = null;
    
    button.addEventListener('click', async () => {
        if (scanner && scanner.scanning) {
            scanner.stop();
            button.textContent = 'Start QR Scanner';
            video.style.display = 'none';
        } else {
            video.style.display = 'block';
            scanner = new QRScanner(video, (result) => {
                resultCallback(result);
                button.textContent = 'Start QR Scanner';
                video.style.display = 'none';
            });
            
            try {
                await scanner.start();
                button.textContent = 'Stop Scanner';
            } catch (error) {
                alert(error.message);
                video.style.display = 'none';
            }
        }
    });
}

// Simple barcode scanner fallback for USB scanners
class BarcodeScanner {
    constructor(inputElement, callback) {
        this.input = inputElement;
        this.callback = callback;
        this.buffer = '';
        this.lastKeyTime = 0;
        this.timeout = null;
        
        this.input.addEventListener('keydown', (e) => this.handleKey(e));
    }
    
    handleKey(e) {
        const currentTime = Date.now();
        
        // If it's a barcode scanner (fast sequential input)
        if (currentTime - this.lastKeyTime < 50) {
            e.preventDefault();
            
            // Clear previous timeout
            if (this.timeout) {
                clearTimeout(this.timeout);
            }
            
            if (e.key === 'Enter') {
                // Barcode scan complete
                this.callback(this.buffer);
                this.buffer = '';
            } else {
                this.buffer += e.key;
                
                // Set timeout in case Enter doesn't come
                this.timeout = setTimeout(() => {
                    if (this.buffer.length > 0) {
                        this.callback(this.buffer);
                        this.buffer = '';
                    }
                }, 200);
            }
        }
        
        this.lastKeyTime = currentTime;
    }
}

// Initialize barcode scanner mode
function initBarcodeMode(inputId, callback) {
    const input = document.getElementById(inputId);
    if (input) {
        new BarcodeScanner(input, callback);
    }
}