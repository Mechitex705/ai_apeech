import asyncio
from pyexpat import model
import websockets
import openai
import tempfile
import datetime
import json
import os
import subprocess

# Set your OpenAI API key
openai.api_key = 'YOUR_OPENAI_API_KEY'

# OpenWebSocket connected clients
connected_clients = set()

async def transcribe_audio_chunk(audio_bytes):
    # Save WebM audio chunk to a temporary file
    with tempfile.NamedTemporaryFile(suffix=".webm", delete=False) as tmp_in:
        tmp_in.write(audio_bytes)
        tmp_in.flush()
        in_path = tmp_in.name

    # Convert WebM to WAV for Whisper
    with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as tmp_out:
        out_path = tmp_out.name
    tmp_out.close()

    try:
        # Use ffmpeg to convert WebM to 16kHz mono WAV
        cmd = [
            "ffmpeg",
            "-y",  # Overwrite output file without asking
            "-i", in_path,  # Input file (WebM)
            "-ar", "16000",  # Sample rate 16kHz
            "-ac", "1",  # Mono channel
            out_path  # Output file (WAV)
        ]
        
        # Run the subprocess and capture stdout and stderr
        result = subprocess.run(cmd, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)

        # Log stdout and stderr for debugging
        print("[FFmpeg Output]:", result.stdout.decode(errors="ignore"))
        print("[FFmpeg Error]:", result.stderr.decode(errors="ignore"))

        # Run Whisper transcription on the WAV file
        result = model.transcribe(out_path)
        return result["text"]

    except subprocess.CalledProcessError as e:
        print("[FFmpeg Error]:", e.stderr.decode(errors="ignore"))
        return "[ERROR] Audio conversion failed"

    except openai.error.APIError as e:
        print(f"OpenAI API error: {e}")
        return "[ERROR] Transcription failed"

    except Exception as e:
        print(f"Unexpected error: {e}")
        return "[ERROR] Transcription failed"

    finally:
        # Clean up the temporary files (WebM and WAV)
        for f in (in_path, out_path):
            try:
                os.remove(f)
            except Exception as e:
                print(f"Error cleaning up file {f}: {str(e)}")

async def handler(websocket):
    connected_clients.add(websocket)
    print(f"Client connected: {websocket.remote_address}")

    try:
        async for message in websocket:
            if isinstance(message, bytes):
                # Received audio chunk as bytes, transcribe it
                transcript = await transcribe_audio_chunk(message)
                if transcript.strip():
                    data = {
                        "timestamp": datetime.datetime.now(datetime.timezone.utc).isoformat(),
                        "transcript": transcript
                    }
                    msg = json.dumps(data)

                    # Broadcast to all other clients (except sender)
                    await asyncio.gather(*[
                        client.send(msg)
                        for client in connected_clients
                        if client != websocket and not client.closed
                    ])
    except websockets.ConnectionClosed:
        print(f"Client disconnected: {websocket.remote_address}")
    except Exception as e:
        print(f"[Handler Error]: {str(e)}")
    finally:
        connected_clients.remove(websocket)

async def main():
    print("WebSocket server running at ws://localhost:8765")
    async with websockets.serve(handler, "localhost", 8765):  # Ensure localhost
        await asyncio.Future()  # run forever

if __name__ == "__main__":
    asyncio.run(main())
