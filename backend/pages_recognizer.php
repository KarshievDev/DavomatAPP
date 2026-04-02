<?php 
// Fetch all employees with profile images for face recognition
$employees_face = [];
try {
    // Check if column exists to avoid fatal error
    $check_col = $pdo->query("SHOW COLUMNS FROM employees LIKE 'profile_image'")->fetch();
    if ($check_col) {
        $employees_face = $pdo->query("SELECT id, full_name, profile_image FROM employees WHERE profile_image IS NOT NULL AND profile_image != ''")->fetchAll();
    } else {
        // Fallback to image_url if profile_image doesn't exist
        $check_url = $pdo->query("SHOW COLUMNS FROM employees LIKE 'image_url'")->fetch();
        if ($check_url) {
            $employees_face = $pdo->query("SELECT id, full_name, image_url as profile_image FROM employees WHERE image_url IS NOT NULL AND image_url != ''")->fetchAll();
        }
    }
} catch (Exception $e) {
    // Silently fail or log
}
?>

<div class="max-w-4xl mx-auto pb-20">
    <div class="flex flex-col items-center mb-8">
        <h1 class="text-[#0e2154] text-3xl font-black tracking-tight mb-2">Face ID Tizimi</h1>
        <p class="text-gray-500 text-sm font-medium">Xodimlarni yuzidan tanish va aniqlash</p>
    </div>

    <!-- Camera Container -->
    <div class="relative bg-black rounded-[32px] overflow-hidden shadow-2xl aspect-video border-8 border-white group">
        <video id="video" autoplay playsinline muted class="w-full h-full object-cover transform scale-x-[-1]"></video>
        <canvas id="overlay" class="absolute inset-0 w-full h-full pointer-events-none z-50"></canvas>
        
        <!-- Loading Overlay -->
        <div id="loader" class="absolute inset-0 bg-slate-900/90 backdrop-blur-md flex flex-col items-center justify-center text-white z-20">
            <div class="w-16 h-16 border-4 border-blue-500 border-t-transparent rounded-full animate-spin mb-4"></div>
            <p class="font-bold tracking-widest text-xs uppercase animate-pulse text-center px-6" id="loaderText">AI Modellar yuklanmoqda...</p>
        </div>

        <!-- Recognition Result Card -->
        <div id="resultBox" class="absolute bottom-6 left-1/2 -translate-x-1/2 bg-white/95 backdrop-blur rounded-2xl px-6 py-4 shadow-2xl border border-white flex items-center gap-4 hidden z-30 transition-all transform translate-y-4">
            <div class="w-14 h-14 rounded-xl bg-gray-100 overflow-hidden shrink-0 border-2 border-blue-100">
                <img id="resultImage" src="" alt="" class="w-full h-full object-cover">
            </div>
            <div>
                <p class="text-[10px] font-black text-blue-600 uppercase tracking-widest mb-0.5">Xodim Aniqlandi</p>
                <h4 id="resultName" class="text-lg font-black text-[#0e2154]">...</h4>
                <div class="flex items-center gap-2 mt-1">
                    <span id="scoreBadge" class="text-[9px] font-bold bg-green-100 text-green-700 px-2 py-0.5 rounded-full uppercase">95% O'XSHASHLIK</span>
                </div>
            </div>
        </div>

        <!-- Attendance Status Toast -->
        <div id="attendanceToast" class="absolute top-6 left-1/2 -translate-x-1/2 bg-green-600 text-white rounded-2xl px-8 py-4 shadow-2xl flex items-center gap-4 hidden z-50 transform scale-0 transition-transform">
            <div class="w-12 h-12 bg-white/20 rounded-full flex items-center justify-center text-2xl">
                <i class="ri-check-line"></i>
            </div>
            <div>
                <div id="toastAction" class="text-[10px] font-bold opacity-80 uppercase tracking-widest">Ishga keldi</div>
                <div id="toastName" class="text-lg font-black leading-tight">...</div>
                <div id="toastTime" class="text-[10px] font-bold opacity-60">14:02</div>
            </div>
        </div>
    </div>

    <!-- Status and Info -->
    <div class="mt-8 grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="bg-white p-6 rounded-3xl shadow-sm border border-gray-100 flex flex-col justify-center">
            <div class="flex items-center justify-between mb-4">
                <span class="text-xs font-bold text-gray-400 uppercase tracking-widest">Tizim holati</span>
                <div id="statusDot" class="w-3 h-3 bg-red-500 rounded-full animate-pulse shadow-[0_0_10px_rgba(239,68,68,0.5)]"></div>
            </div>
            <div id="statusText" class="text-lg font-black text-[#0e2154] flex items-center gap-2">
                <i class="ri-time-line text-blue-500 text-sm"></i>
                <span>Tayyorlanmoqda...</span>
            </div>
        </div>
        <div class="hidden md:block bg-gray-900 p-6 rounded-3xl shadow-inner overflow-hidden border-4 border-gray-800">
            <h3 class="text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-3 flex items-center gap-2">
                <i class="ri-code-line text-[#10b981]"></i> System Debug Console
            </h3>
            <div id="debugLog" class="text-[11px] font-mono text-[#10b981] h-32 overflow-y-auto space-y-1">
                <div>[SYSTEM] Initalizing...</div>
            </div>
        </div>
    </div>
</div>

<!-- Dependencies -->
<script src="https://cdn.jsdelivr.net/npm/@vladmandic/face-api/dist/face-api.js"></script>

<script>
const video = document.getElementById('video');
const overlay = document.getElementById('overlay');
const resultBox = document.getElementById('resultBox');
const resultName = document.getElementById('resultName');
const resultImage = document.getElementById('resultImage');
const scoreBadge = document.getElementById('scoreBadge');
const loader = document.getElementById('loader');
const loaderText = document.getElementById('loaderText');
const statusDot = document.getElementById('statusDot');
const statusText = document.getElementById('statusText');

const debugLog = document.getElementById('debugLog');

function log(msg, type = 'info') {
    const time = new Date().toLocaleTimeString();
    const color = type === 'error' ? 'text-red-500' : 'text-[#10b981]';
    debugLog.innerHTML += `<div class="${color}">[${time}] ${msg}</div>`;
    debugLog.scrollTop = debugLog.scrollHeight;
    console.log(`[${time}] ${msg}`);
}

const employeeList = <?= json_encode($employees_face) ?>;
let faceMatcher;

// Load AI Models and Camera
async function initFaceID() {
    try {
        log("Tizim ishga tushmoqda...");
        
        if (typeof faceapi === 'undefined') {
            log("Xatolik: Face-API kutubxonasi yuklanmadi!", "error");
            throw new Error("Kutubxona yuklanmadi. Internetni tekshiring.");
        }

        // 1. Request Camera FIRST
        log("Kameraga so'rov yuborilmoqda...");
        loaderText.innerText = "Kameraga ruxsat so'ralmoqda...";
        
        const stream = await navigator.mediaDevices.getUserMedia({ video: {} })
            .catch(e => {
                log(`Kamera xatosi: ${e.message}`, "error");
                throw e;
            });
            
        video.srcObject = stream;
        log("Kamera muvaffaqiyatli ulandi.");
        
        await new Promise(resolve => video.onloadedmetadata = resolve);

        // 2. Load Models
        const MODEL_URL = 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api/model/';
        log("AI Modellari yuklanmoqda...");
        
        loaderText.innerText = "AI Modellar yuklanmoqda (Detection)...";
        await faceapi.nets.ssdMobilenetv1.loadFromUri(MODEL_URL);
        await faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL);
        log("Detection modellari (SSD & Tiny) yuklandi.");
        
        loaderText.innerText = "AI Modellar yuklanmoqda (Recognition)...";
        await faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL);
        await faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL);
        log("Recognition modellari yuklandi.");
        
        loaderText.innerText = "Xodimlar bazasi tayyorlanmoqda...";
        if (employeeList.length > 0) {
            log(`${employeeList.length} ta xodim bazadan olindi.`);
            await prepareFaceMatcher();
            log("Face Matcher tayyor.");
        } else {
            log("Ogohlantirish: Bazada rasmli xodimlar yo'q!", "error");
        }

        loader.classList.add('hidden');
        statusDot.classList.replace('bg-red-500', 'bg-green-500');
        statusDot.classList.remove('animate-pulse');
        statusDot.style.boxShadow = '0 0 12px rgba(16, 185, 129, 0.6)';
        statusText.innerHTML = '<i class="ri-checkbox-circle-fill text-green-500 text-sm"></i> <span>Tanishga tayyor</span>';
        log("TIZIM TAYYOR. Tanish boshlandi.");

        recognize();
    } catch (err) {
        log(`Kritik xatolik: ${err.message}`, "error");
        let errorMsg = err.message;
        if (err.name === 'NotAllowedError') errorMsg = "Kameraga ruxsat berilmadi. Browser sozlamalaridan ruxsat bering.";
        
        loaderText.innerHTML = `<span class="text-red-500 font-black">XATOLIK:</span><br>${errorMsg}<br><br><button onclick="location.reload()" class="px-8 py-3 bg-blue-600 rounded-2xl font-black text-xs tracking-widest shadow-lg shadow-blue-600/30 active:scale-95 transition-all">QAYTA URINISH</button>`;
        statusText.innerHTML = '<i class="ri-close-circle-fill text-red-500 text-sm"></i> <span class="text-red-500">Xatolik!</span>';
    }
}

async function prepareFaceMatcher() {
    const labeledDescriptors = await Promise.all(
        employeeList.map(async emp => {
            try {
                log(`Rasm yuklanmoqda: ${emp.full_name}...`);
                if (!emp.profile_image) {
                    log(`Rasm topilmadi: ${emp.full_name}`, "error");
                    return null;
                }
                
                const img = await faceapi.fetchImage(emp.profile_image);
                log(`Rasm o'qildi: ${emp.full_name}. Tahlil qilinmoqda...`);
                
                // Use the more accurate SSD Detector for reference images
                const detection = await faceapi.detectSingleFace(img).withFaceLandmarks().withFaceDescriptor();
                
                if (detection) {
                    log(`Muvaffaqiyat: ${emp.full_name} yuzi aniqlandi.`, "info");
                    return new faceapi.LabeledFaceDescriptors(emp.full_name, [detection.descriptor]);
                } else {
                    log(`Xatolik: ${emp.full_name} rasmida yuz topilmadi!`, "error");
                    return null;
                }
            } catch (e) {
                log(`Rasm tahlilida xato (${emp.full_name}): ${e.message}`, "error");
                return null;
            }
        })
    );

    const validDescriptors = labeledDescriptors.filter(d => d !== null);
    if (validDescriptors.length > 0) {
        faceMatcher = new faceapi.FaceMatcher(validDescriptors, 0.6); // Distance threshold 0.6
        log(`Tizimga ${validDescriptors.length} ta xodim muvaffaqiyatli yuklandi.`);
    } else {
        throw new Error("Bazadan birorta ham yaroqli (yuzi aniq ko'ringan) rasm topilmadi");
    }
}

const lastProcessed = new Map(); 
let lastSuccessEmpId = null; 
let isProcessingAttendance = false; // Prevent multiple concurrent requests

const attendanceToast = document.getElementById('attendanceToast');
const toastName = document.getElementById('toastName');
const toastAction = document.getElementById('toastAction');
const toastTime = document.getElementById('toastTime');

function playSuccessSound(text) {
    if ('speechSynthesis' in window) {
        const utterance = new SpeechSynthesisUtterance(text);
        utterance.lang = 'tr-TR'; // Uzbek isn't widely supported, Turkish is closest phonetically for simple names
        utterance.rate = 1.0;
        window.speechSynthesis.speak(utterance);
    }
}

async function saveAttendance(empId) {
    empId = parseInt(empId);
    console.log("saveAttendance called for ID:", empId); // Browser console
    
    if (isProcessingAttendance) return;

    const now = Date.now();
    const lastTime = lastProcessed.get(empId) || 0;
    const waitTime = 10000; // 10 seconds for testing
    
    if (now - lastTime < waitTime) {
        // Just log internally skip
        return;
    }
    
    isProcessingAttendance = true;
    log(`[SISTEMA] Bog'lanish boshlandi (ID: ${empId})...`, "info");
    statusDot.classList.add('animate-ping');
    playSuccessSound("Iltimos kuting");

    const offscreenCanvas = document.createElement('canvas');
    offscreenCanvas.width = video.videoWidth;
    offscreenCanvas.height = video.videoHeight;
    offscreenCanvas.getContext('2d').drawImage(video, 0, 0);
    const snapBase64 = offscreenCanvas.toDataURL('image/jpeg', 0.5);

    const formData = new FormData();
    formData.append('employee_id', empId);
    formData.append('image_base64', snapBase64);

    try {
        const response = await fetch('api_face_attendance.php', {
            method: 'POST',
            body: formData,
            credentials: 'include'
        });
        
        const text = await response.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch(e) {
            log(`Server xatosi (JSON emas): ${text.substring(0, 50)}`, "error");
            throw new Error("Server noto'g'ri javob qaytardi");
        }
        
        if (data.status === 'success') {
            log(`MUVAFFAQIYAT: ${data.message}`, "info");
            lastSuccessEmpId = empId;
            lastProcessed.set(empId, now);
            showToast(data);
        } else {
            log(`Xatolik: ${data.message}`, "error");
            playSuccessSound("Xatolik yuz berdi");
        }
    } catch (e) {
        log(`Tarmoq xatosi: ${e.message}`, "error");
    } finally {
        isProcessingAttendance = false;
        statusDot.classList.remove('animate-ping');
    }
}

function showToast(data) {
    const isCheckIn = (data.action === 'check-in');
    
    if (isCheckIn) {
        toastAction.innerText = "XUSH KELIBSIZ";
        toastName.innerText = data.name;
        toastTime.innerText = `Kirish qayd etildi: ${data.time}`;
        attendanceToast.style.backgroundColor = "#059669"; // Emerald
        playSuccessSound(`Xush kelibsiz ${data.name}`);
    } else {
        toastAction.innerText = "XAYR";
        toastName.innerText = data.name;
        toastTime.innerText = `Chiqish qayd etildi: ${data.time}`;
        attendanceToast.style.backgroundColor = "#2563eb"; // Blue
        playSuccessSound(`Xayr ${data.name}`);
    }
    
    attendanceToast.classList.remove('hidden');
    setTimeout(() => attendanceToast.classList.add('scale-100'), 10);
    
    setTimeout(() => {
        attendanceToast.classList.remove('scale-100');
        setTimeout(() => {
            attendanceToast.classList.add('hidden');
        }, 300);
    }, 5000);
}

async function recognize() {
    // Match overlay to the ACTUAL DISPLAY size of the video element
    const displaySize = { 
        width: video.offsetWidth, 
        height: video.offsetHeight 
    };
    faceapi.matchDimensions(overlay, displaySize);
    
    log(`Detector ishga tushdi (${displaySize.width}x${displaySize.height})`);

    // Using SsdMobilenetv1 for better detection if possible, or Tiny for speed
    const ssdOptions = new faceapi.SsdMobilenetv1Options({ minConfidence: 0.3 });

    async function runRecognition() {
        if (video.paused || video.ended) {
            requestAnimationFrame(runRecognition);
            return;
        }

        try {
            // Recalculate display size in case of window resize or rotation
            const currentSize = { width: video.offsetWidth, height: video.offsetHeight };
            if (currentSize.width !== displaySize.width) {
                displaySize.width = currentSize.width;
                displaySize.height = currentSize.height;
                faceapi.matchDimensions(overlay, displaySize);
            }

            const detections = await faceapi.detectAllFaces(video, ssdOptions)
                .withFaceLandmarks()
                .withFaceDescriptors();
            
            const resizedDetections = faceapi.resizeResults(detections, displaySize);
            const ctx = overlay.getContext('2d');
            ctx.clearRect(0, 0, overlay.width, overlay.height);

            if (detections.length > 0) {
                statusText.innerHTML = `<i class="ri-checkbox-circle-fill text-green-500 text-sm"></i> <span>Tanishilmoqda (${detections.length} ta yuz)...</span>`;
                
                resizedDetections.forEach(detection => {
                    const result = faceMatcher.findBestMatch(detection.descriptor);
                    const box = detection.detection.box;
                    
                    // Recognition threshold
                    const isMatch = result.label !== 'unknown' && result.distance < 0.65; 
                    
                    // Log findings to debug console for live feedback
                    const confidence = Math.round((1 - result.distance) * 100);
                    log(`Aniqlangan: ${result.label} (${confidence}%)`, isMatch ? 'info' : 'error');

                    // Mirroring Fix: Drawing correctly on a mirrored display video
                    const mirroredBox = new faceapi.Box(
                        displaySize.width - box.x - box.width, 
                        box.y, 
                        box.width, 
                        box.height
                    );

                    const drawBox = new faceapi.draw.DrawBox(mirroredBox, { 
                        label: isMatch ? `${result.label} (${confidence}%)` : `Noma'lum (${confidence}%)`,
                        boxColor: isMatch ? '#10b981' : '#ef4444'
                    });
                    drawBox.draw(overlay);

                    if (isMatch) {
                        const emp = employeeList.find(e => e.full_name === result.label);
                        if (emp) {
                            showEmployee(result);
                            saveAttendance(emp.id);
                        } else {
                            log(`DEBUG: Xodim topilmadi (${result.label})`, "error");
                        }
                    }
                });
            } else {
                statusText.innerHTML = `<i class="ri-loader-4-line text-blue-500 animate-spin text-sm"></i> <span>Yuz qidirilmoqda...</span>`;
                hideEmployee();
            }
        } catch (e) {
            console.error("Recognition Error:", e);
        }
        
        requestAnimationFrame(runRecognition);
    }

    runRecognition();
}

let hideTimeout;
function showEmployee(result) {
    if (hideTimeout) {
        clearTimeout(hideTimeout);
        hideTimeout = null;
    }
    const emp = employeeList.find(e => e.full_name === result.label);
    if (emp) {
        resultName.innerText = emp.full_name;
        resultImage.src = emp.profile_image;
        const confidence = Math.round((1 - result.distance) * 100);
        scoreBadge.innerText = `${confidence}% O'XSHASHLIK`;
        
        resultBox.classList.remove('hidden');
        resultBox.classList.remove('translate-y-4');
    }
}

function hideEmployee() {
    // Only set the timeout if it's not already scheduled
    if (hideTimeout) return;

    hideTimeout = setTimeout(() => {
        resultBox.classList.add('hidden');
        resultBox.classList.add('translate-y-4');
        lastSuccessEmpId = null; // Person has left or a face was lost for 3 seconds
        hideTimeout = null; // Reset for next use
    }, 3000); 
}

// Start
initFaceID();

</script>
