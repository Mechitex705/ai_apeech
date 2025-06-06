import asyncio
import websockets
import tempfile
import datetime
import json
import os
import wave
import logging
from openai import OpenAI
from dotenv import load_dotenv

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

# Load environment variables
load_dotenv()

# Initialize OpenAI client
client = OpenAI(api_key=os.getenv('OPENAI_API_KEY'))

connected_clients = set()

def create_valid_wav(audio_bytes, sample_rate=16000):
    """Convert raw audio bytes to properly formatted WAV file"""
    try:
        with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as tmp_file:
            with wave.open(tmp_file, 'wb') as wav:
                wav.setnchannels(1)  # Mono
                wav.setsampwidth(2)  # 16-bit
                wav.setframerate(sample_rate)  # 16kHz
                wav.writeframes(audio_bytes)
            return tmp_file.name
    except Exception as e:
        logger.error(f"WAV creation failed: {e}")
        raise

async def transcribe_audio_chunk(audio_bytes):
    """Process audio chunk through Whisper API"""
    # Minimum size check (0.1s of 16kHz 16-bit mono = 3200 bytes)
    if len(audio_bytes) < 3200:
        logger.warning("Audio chunk too short (min 0.1s required)")
        return None

    try:
        wav_path = create_valid_wav(audio_bytes)
        logger.debug(f"Created WAV file at {wav_path}")

        with open(wav_path, 'rb') as audio_file:
            response = client.audio.transcriptions.create(
                model="whisper-1",
                file=audio_file,
                response_format="text"
            )
            return response
    except Exception as e:
        logger.error(f"Transcription failed: {str(e)}")
        return None
    finally:
        try:
            os.unlink(wav_path)
        except:
            pass

async def handler(websocket, path=None):
    """Handle WebSocket connections"""
    connected_clients.add(websocket)
    client_ip = websocket.remote_address[0] if websocket.remote_address else "unknown"
    logger.info(f"New connection from {client_ip}")

    try:
        async for message in websocket:
            if isinstance(message, bytes):
                # Process audio chunk
                transcript = await transcribe_audio_chunk(message)
                
                if transcript:
                    response = {
                        "timestamp": datetime.datetime.now(datetime.timezone.utc).isoformat(),
                        "transcript": transcript,
                        "client": client_ip
                    }
                    msg = json.dumps(response)

                    # Broadcast to other clients (using connection state tracking)
                    tasks = []
                    for client in connected_clients:
                        if client != websocket:
                            try:
                                tasks.append(client.send(msg))
                            except:
                                connected_clients.discard(client)
                    if tasks:
                        await asyncio.gather(*tasks)
    except websockets.exceptions.ConnectionClosed:
        logger.info(f"Client {client_ip} disconnected")
    except Exception as e:
        logger.error(f"Handler error: {str(e)}")
    finally:
        connected_clients.discard(websocket)

async def main():
    """Start WebSocket server"""
    logger.info("Starting server on ws://localhost:8765")
    async with websockets.serve(
        handler,
        "localhost",
        8765,
        ping_interval=30,
        ping_timeout=30,
        close_timeout=10
    ):
        await asyncio.Future()  # Run forever

if __name__ == "__main__":
    # Verify environment
    if not os.getenv('OPENAI_API_KEY'):
        logger.error("Missing OPENAI_API_KEY in environment")
        exit(1)

    # Run server
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        logger.info("Server stopped by user")
    except Exception as e:
        logger.error(f"Server crashed: {str(e)}")
