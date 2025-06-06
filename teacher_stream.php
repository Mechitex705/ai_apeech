<?php
// Verify teacher is authorized for this class
$class_id = $_GET['class_id'] ?? 0;
$tblquery = "SELECT * FROM live_classes WHERE id = :id AND teacher = :teacher";
$tblvalue = [':id' => $class_id, ':teacher' => $_SESSION['username']];
$class = $dblink->tbl_select($tblquery, $tblvalue);

if (!$class) {
  die('<div class="alert alert-danger">Unauthorized access</div>');
}

?>

<div class="container-fluid">
  <div class="row">
    <div class="col-md-8 mx-auto">
      <div class="card shadow-lg">
        <div class="card-header bg-primary text-white">
          <h3 class="card-title">
            <i class="fas fa-chalkboard-teacher"></i> 
            <?= htmlspecialchars($class[0]['class_subject']) ?> - Live Transcription
          </h3>
        </div>
        <div class="card-body">
          <!-- Audio Controls -->
          <div id="audioContainer" class="mb-4">
            <button id="startTranscription" class="btn btn-success btn-lg">
              <i class="fas fa-microphone"></i> Start Transcription
            </button>
            <button id="stopTranscription" class="btn btn-danger btn-lg" disabled>
              <i class="fas fa-stop-circle"></i> Stop
            </button>
            <div id="transcriptionStatus" class="mt-3"></div>
          </div>
          
          <!-- Transcript Display -->
          <div id="transcriptContainer" class="mt-4" style="display:none;">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h4 class="text-primary">
                <i class="fas fa-scroll"></i> Live Transcript
              </h4>
              <div class="btn-group">
                <button id="saveTranscriptBtn" class="btn btn-outline-primary" disabled>
                  <i class="fas fa-save"></i> Save
                </button>
                <button id="clearTranscriptBtn" class="btn btn-outline-secondary">
                  <i class="fas fa-trash-alt"></i> Clear
                </button>
              </div>
            </div>
            
            <div id="transcriptionOutput" class="p-3 bg-white border rounded shadow-sm" 
                 style="height: 400px; overflow-y: auto;"></div>
            
            <div class="mt-3">
              <div class="progress">
                <div id="audioLevel" class="progress-bar bg-info" role="progressbar" 
                     style="width: 0%"></div>
              </div>
              <small class="text-muted">Audio input level</small>
            </div>
          </div>
        </div>
        <div class="card-footer text-muted">
          <small>Session ID: <?= session_id() ?> | Class ID: <?= $class_id ?></small>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Hidden CSRF Token -->
<input type="hidden" id="csrfToken" value="<?= $csrf_token ?>">

<style>
/* Enhanced Transcript Styling */
#transcriptionOutput {
  background: white;
  border: 1px solid #e0e0e0;
  border-radius: 5px;
  padding: 15px;
  background-color: #fafafa;
}

.transcript-segment {
  padding: 10px;
  margin-bottom: 10px;
  border-radius: 5px;
  border-left: 4px solid #ddd;
  background-color: white;
  box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.high-confidence {
  border-left-color: #28a745;
  background-color: rgba(40, 167, 69, 0.05);
}

.medium-confidence {
  border-left-color: #ffc107;
  background-color: rgba(255, 193, 7, 0.05);
}

.low-confidence {
  border-left-color: #dc3545;
  background-color: rgba(220, 53, 69, 0.05);
}

.segment-meta {
  display: flex;
  justify-content: space-between;
  margin-top: 5px;
  font-size: 0.8em;
}

.confidence-badge {
  display: inline-block;
  padding: 0 5px;
  border-radius: 10px;
  background-color: #f0f0f0;
  font-weight: bold;
}

.high-confidence .confidence-badge {
  background-color: #d4edda;
  color: #155724;
}

.medium-confidence .confidence-badge {
  background-color: #fff3cd;
  color: #856404;
}

.low-confidence .confidence-badge {
  background-color: #f8d7da;
  color: #721c24;
}

/* Button enhancements */
.btn-lg {
  padding: 0.5rem 1.5rem;
  font-size: 1.1rem;
  margin-right: 10px;
}

/* Responsive adjustments */
@media (max-width: 768px) {
  .btn-lg {
    display: block;
    width: 100%;
    margin-bottom: 10px;
  }
  
  #transcriptionOutput {
    height: 300px;
  }
}
</style>

<script>
let mediaRecorder;
let socket;
let audioContext;
let analyser;
let audioChunks = [];
let lastSendTime = Date.now();

const startBtn = document.getElementById('startTranscription');
const stopBtn = document.getElementById('stopTranscription');
const statusDiv = document.getElementById('transcriptionStatus');
const transcriptContainer = document.getElementById('transcriptContainer');
const transcriptionOutput = document.getElementById('transcriptionOutput');
const audioLevelMeter = document.getElementById('audioLevel');

const classId = <?= json_encode($class_id) ?>;

// Initialize UI
document.addEventListener('DOMContentLoaded', function() {
  // Initialize any dashboard components here
  const sparklineEl = document.getElementById('sparkline-element');
  if (sparklineEl) {
    try {
      new Sparkline(sparklineEl);
    } catch (e) {
      console.error('Sparkline initialization failed:', e);
    }
  }
});

startBtn.onclick = async () => {
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    showAlert('Your browser does not support audio capture.', 'danger');
    return;
  }

  try {
    // Clear previous transcripts
    transcriptionOutput.innerHTML = '';
    
    // Connect to WebSocket
    setupWebSocket();

    // Request microphone access with optimal settings
    const stream = await navigator.mediaDevices.getUserMedia({ 
      audio: {
        sampleRate: 16000,
        channelCount: 1,
        echoCancellation: true,
        noiseSuppression: true,
        autoGainControl: true
      },
      video: false
    });

    // Set up audio visualization
    setupAudioMeter(stream);

    // Configure media recorder
    mediaRecorder = new MediaRecorder(stream, { 
      mimeType: 'audio/webm;codecs=opus',
      audioBitsPerSecond: 16000
    });

    // Handle audio data
    mediaRecorder.ondataavailable = handleAudioData;
    
    // Start recording with small timeslice for frequent updates
    mediaRecorder.start(100);

    // Update UI
    startBtn.disabled = true;
    stopBtn.disabled = false;
    transcriptContainer.style.display = 'block';
    statusDiv.textContent = 'Recording...';
    statusDiv.className = 'text-success';

  } catch (err) {
    handleError('Error accessing microphone: ' + err.message, err);
    if (socket) socket.close();
  }
};

stopBtn.onclick = () => {
  try {
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
      mediaRecorder.stream.getTracks().forEach(track => track.stop());
      mediaRecorder.stop();
    }
    if (socket) {
      socket.close();
    }
    if (audioContext) {
      audioContext.close();
    }
    
    startBtn.disabled = false;
    stopBtn.disabled = true;
    statusDiv.textContent = 'Stopped.';
    statusDiv.className = 'text-muted';
    
  } catch (err) {
    handleError('Error stopping recording: ' + err.message, err);
  }
};

// WebSocket Functions
function setupWebSocket() {
  const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
  const wsUrl = `${protocol}//${window.location.hostname}:8765`;
  
  socket = new WebSocket(wsUrl);

  socket.onopen = () => {
    console.log('WebSocket connection established');
    statusDiv.textContent = 'Connected to transcription server';
    statusDiv.className = 'text-success';
  };

  socket.onclose = (event) => {
    console.log('WebSocket closed:', event);
    statusDiv.textContent = `Disconnected: ${event.reason || 'Connection closed'}`;
    statusDiv.className = 'text-danger';
    
    // Attempt reconnect if we were recording
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
      setTimeout(setupWebSocket, 2000);
    }
  };

  socket.onerror = (error) => {
    console.error('WebSocket error:', error);
    statusDiv.textContent = 'WebSocket error occurred';
    statusDiv.className = 'text-danger';
  };

  socket.onmessage = (event) => {
    try {
      const data = JSON.parse(event.data);
      if (data.transcript) {
        addTranscriptSegment(data.transcript, data.timestamp);
      }
    } catch (e) {
      console.error('Error processing message:', e);
    }
  };
}

// Audio Processing Functions
function setupAudioMeter(stream) {
  audioContext = new (window.AudioContext || window.webkitAudioContext)();
  analyser = audioContext.createAnalyser();
  analyser.fftSize = 32;
  const source = audioContext.createMediaStreamSource(stream);
  source.connect(analyser);
  
  const updateMeter = () => {
    const array = new Uint8Array(analyser.frequencyBinCount);
    analyser.getByteFrequencyData(array);
    const avg = array.reduce((a, b) => a + b) / array.length;
    const level = Math.min(100, Math.max(0, avg));
    audioLevelMeter.style.width = `${level}%`;
    
    // Color feedback (green/yellow/red)
    if (level < 30) {
      audioLevelMeter.className = 'progress-bar bg-danger';
    } else if (level < 70) {
      audioLevelMeter.className = 'progress-bar bg-warning';
    } else {
      audioLevelMeter.className = 'progress-bar bg-success';
    }
    
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
      requestAnimationFrame(updateMeter);
    }
  };
  
  updateMeter();
}

async function handleAudioData(event) {
  if (event.data.size > 0) {
    audioChunks.push(event.data);
    
    // Send every 2 seconds or when 50kb is reached
    if (audioChunks.length > 0 && (Date.now() - lastSendTime > 2000 || 
        audioChunks.reduce((a, b) => a + b.size, 0) > 50000)) {
      if (socket && socket.readyState === WebSocket.OPEN) {
        try {
          const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
          const buffer = await audioBlob.arrayBuffer();
          socket.send(buffer);
          console.log("Sent audio chunk:", buffer.byteLength, "bytes");
        } catch (e) {
          console.error('Error sending audio:', e);
        }
      }
      audioChunks = [];
      lastSendTime = Date.now();
    }
  }
}

// UI Functions
function addTranscriptSegment(text, timestamp) {
  const segment = document.createElement('div');
  segment.className = 'transcript-segment high-confidence';
  
  const time = new Date(timestamp);
  const timeString = time.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
  
  segment.innerHTML = `
    <div class="transcript-text">${escapeHtml(text)}</div>
    <div class="segment-meta">
      <span>${timeString}</span>
      <span class="confidence-badge">High confidence</span>
    </div>
  `;
  
  transcriptionOutput.appendChild(segment);
  transcriptionOutput.scrollTop = transcriptionOutput.scrollHeight;
}

function showAlert(message, type = 'danger') {
  const alertDiv = document.createElement('div');
  alertDiv.className = `alert alert-${type} alert-dismissible fade show mt-3`;
  alertDiv.innerHTML = `
    ${message}
    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
      <span aria-hidden="true">&times;</span>
    </button>
  `;
  statusDiv.appendChild(alertDiv);
  
  setTimeout(() => {
    alertDiv.classList.remove('show');
    setTimeout(() => alertDiv.remove(), 150);
  }, 5000);
}

function handleError(message, error) {
  console.error(message, error);
  showAlert(message, 'danger');
}

function escapeHtml(unsafe) {
  return unsafe
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

// Cleanup on page exit
window.addEventListener('beforeunload', () => {
  if (socket) socket.close();
  if (mediaRecorder && mediaRecorder.state !== 'inactive') {
    mediaRecorder.stop();
  }
});
</script>
