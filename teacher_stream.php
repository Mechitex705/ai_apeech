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

const startBtn = document.getElementById('startTranscription');
const stopBtn = document.getElementById('stopTranscription');
const statusDiv = document.getElementById('transcriptionStatus');
const transcriptContainer = document.getElementById('transcriptContainer');

const classId = <?= json_encode($class_id) ?>;

startBtn.onclick = async () => {
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    alert('Your browser does not support audio capture.');
    return;
  }

  // Connect to the WebSocket server
  socket = new WebSocket('ws://localhost:8765');

  socket.onopen = () => {
    statusDiv.textContent = 'Connected to transcription server.';
  };

  socket.onclose = () => {
    statusDiv.textContent = 'Disconnected from transcription server.';
  };

  socket.onerror = (e) => {
    console.error('WebSocket error', e);
    statusDiv.textContent = 'WebSocket error occurred.';
  };

  try {
    // Request microphone access
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });

    // Use MediaRecorder to capture audio in WebM format (audio/webm is widely supported)
    mediaRecorder = new MediaRecorder(stream, { mimeType: 'audio/webm' });

    mediaRecorder.ondataavailable = async (event) => {
      if (event.data.size > 0 && socket.readyState === WebSocket.OPEN) {
        // Log the audio chunk size for debugging
        console.log("Audio chunk size:", event.data.size);

        // Convert the audio chunk into ArrayBuffer and send over WebSocket
        const buffer = await event.data.arrayBuffer();
        socket.send(buffer);  // Send raw WebM audio to server
      }
    };

    // Start recording and send chunks every 2 seconds
    mediaRecorder.start(2000);

    startBtn.disabled = true;
    stopBtn.disabled = false;
    transcriptContainer.style.display = 'block';
    statusDiv.textContent = 'Recording...';
  } catch (err) {
    alert('Error accessing microphone: ' + err.message);
  }
};

stopBtn.onclick = () => {
  if (mediaRecorder && mediaRecorder.state !== 'inactive') {
    mediaRecorder.stop();
  }
  if (socket) {
    socket.close();
  }
  startBtn.disabled = false;
  stopBtn.disabled = true;
  statusDiv.textContent = 'Stopped.';
};
</script>
