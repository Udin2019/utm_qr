FROM python:3.12-slim-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends inkscape && rm -rf /var/lib/apt/lists/*
RUN python3 -m pip install --no-cache-dir zxing-cpp Pillow
COPY tests/qr_decode.py /qa/qr_decode.py
ENTRYPOINT ["python3", "/qa/qr_decode.py", "/artifacts"]
