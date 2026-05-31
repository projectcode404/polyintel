FROM python:3.13-slim

WORKDIR /app

# System dependencies — psycopg2 butuh libpq, python-dateutil butuh nothing extra
RUN apt-get update && apt-get install -y \
    libpq-dev \
    gcc \
    && rm -rf /var/lib/apt/lists/*

# Install Python dependencies SEBELUM copy code
# Layer caching: kalau requirements.txt tidak berubah, layer ini di-cache
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copy seluruh project
COPY . .

# PYTHONPATH — critical: tanpa ini "from config.settings import settings"
# akan gagal dengan ModuleNotFoundError karena Python tidak tahu
# bahwa /app adalah root dari semua imports
ENV PYTHONPATH=/app

# Logging format JSON untuk production (mudah di-parse oleh log aggregator)
ENV LOG_FORMAT=json

# Non-root user untuk keamanan
RUN useradd -m -u 1001 collector && \
    chown -R collector:collector /app
USER collector

# Entry point
CMD ["python", "scheduler.py"]
