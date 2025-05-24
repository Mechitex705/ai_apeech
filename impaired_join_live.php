<div class="container-fluid">
  <div class="row">
    <div class="col-md-12">
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Join Live Class</h3>
          <div class="card-tools">
            <button type="button" class="btn btn-tool" data-card-widget="collapse">
              <i class="fas fa-minus"></i>
            </button>
          </div>
        </div>
        <div class="card-body">
          <?php
          $tblquery = "SELECT * FROM live_classes WHERE status = ? AND (enable_transcription = 1 OR transcription = 1)";
          $tblvalue = ['ongoing'];
          $classes = $dblink->tbl_select($tblquery, $tblvalue);

          if ($classes) {
            foreach ($classes as $class) {
              $classId = $class['id'];
              $meetLink = htmlspecialchars($class['class_link']);
          ?>
            <div class="class-card mb-4 p-3 border rounded">
              <div class="d-flex justify-content-between align-items-center">
                <div>
                  <h4><?= htmlspecialchars($class['class_subject']) ?> - <?= htmlspecialchars($class['class_group']) ?></h4>
                  <p class="mb-1"><strong>Teacher:</strong> <?= htmlspecialchars($class['teacher']) ?></p>
                  <p class="mb-1"><strong>Started:</strong> <?= date("F j, Y g:i a", strtotime($class['start_time'])) ?></p>
                </div>
                
                <button class="btn btn-success" onclick="joinImpairedClass('<?= $meetLink ?>', <?= $classId ?>)">
                  <i class="fas fa-sign-in-alt"></i> Join Class
                </button>
              </div>

              <!-- Captions Container -->
              <div id="captionWrapper-<?= $classId ?>" class="mt-3" style="display:none;">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <h5>Live Captions</h5>
                  <div>
                    <button class="btn btn-sm btn-info" onclick="adjustCaptionSize('increase', <?= $classId ?>)">
                      <i class="fas fa-search-plus"></i>
                    </button>
                    <button class="btn btn-sm btn-info" onclick="adjustCaptionSize('decrease', <?= $classId ?>)">
                      <i class="fas fa-search-minus"></i>
                    </button>
                    <button class="btn btn-sm btn-warning" onclick="toggleContrast(<?= $classId ?>)">
                      <i class="fas fa-adjust"></i> Contrast
                    </button>
                  </div>
                </div>

                <div id="liveCaptions-<?= $classId ?>" class="p-3 bg-light border rounded caption-display">
                  <p class="text-center text-muted">Captions will appear here when the teacher starts speaking</p>
                </div>

                <div class="mt-2">
                  <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" id="autoScroll-<?= $classId ?>" checked>
                    <label class="form-check-label" for="autoScroll-<?= $classId ?>">Auto-scroll</label>
                  </div>
                </div>
              </div>
            </div>
          <?php
            }
          } else {
            echo '<div class="alert alert-info">No live classes with transcription available at the moment.</div>';
          }
          ?>
        </div>
      </div>
    </div>
  </div>
</div>

<style>
  .class-card {
    background-color: #fff;
    transition: all 0.3s ease;
  }
  .class-card:hover {
    box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
  }
  .caption-display {
    height: 250px;
    overflow-y: auto;
    font-size: 1.1em;
    line-height: 1.6;
    background-color: #f8f9fa;
    transition: all 0.3s ease;
  }
  .caption-item {
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid #eee;
  }
  .high-contrast {
    background-color: #000 !important;
    color: #ff0 !important;
    border-color: #fff !important;
  }
  .high-contrast .caption-item {
    border-bottom-color: #ff0 !important;
  }
  .caption-display::-webkit-scrollbar {
    width: 8px;
  }
  .caption-display::-webkit-scrollbar-track {
    background: #f1f1f1;
  }
  .caption-display::-webkit-scrollbar-thumb {
    background: #8b3c3c;
    border-radius: 4px;
  }
  .caption-display::-webkit-scrollbar-thumb:hover {
    background: #6a2c2c;
  }
</style>

<script>
document.addEventListener("DOMContentLoaded", () => {
  window.joinImpairedClass = function(meetLink, classId) {
    const captionsDiv = document.getElementById('liveCaptions-' + classId);
    const captionWrapper = document.getElementById('captionWrapper-' + classId);
    
    if (!captionsDiv || !captionWrapper) return;

    // Display captions container
    captionWrapper.style.display = 'block';

    // Prevent multiple WebSocket connections per class
    if (captionWrapper.dataset.connected === "true") {
      console.log("Already connected to class " + classId);
      return;
    }
    captionWrapper.dataset.connected = "true";

    // Connect to the WebSocket server
    const socket = new WebSocket('ws://localhost:8765'); // Ensure the correct WebSocket server address

    socket.onopen = () => {
      console.log('Connected to live transcription for class ' + classId);
    };

    socket.onmessage = (event) => {
      try {
        const data = JSON.parse(event.data);
        if (data.transcript) {
          const p = document.createElement('p');
          p.textContent = data.transcript;
          p.classList.add('caption-item');
          captionsDiv.appendChild(p);

          // Auto-scroll
          const autoScroll = document.getElementById('autoScroll-' + classId);
          if (autoScroll && autoScroll.checked) {
            captionsDiv.scrollTop = captionsDiv.scrollHeight;
          }
        }
      } catch (error) {
        console.error('Error parsing WebSocket message:', error);
      }
    };

    socket.onerror = (e) => {
      console.error('WebSocket error', e);
      const errorMsg = document.createElement('p');
      errorMsg.textContent = 'Error: Could not receive live transcription.';
      errorMsg.classList.add('caption-item');
      captionsDiv.appendChild(errorMsg);
    };

    socket.onclose = () => {
      const p = document.createElement('p');
      p.textContent = 'Live transcription ended.';
      captionsDiv.appendChild(p);
      captionWrapper.dataset.connected = "false";
    };
  }
});
</script>
