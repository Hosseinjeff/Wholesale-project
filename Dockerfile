# Use Python 3.11 slim as base image
FROM python:3.11-slim

# One glibc arena, and return freed pages, so an idle worker stays small.
ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1 \
    MALLOC_ARENA_MAX=2 \
    MALLOC_TRIM_THRESHOLD_=131072 \
    PORT=8080

# Create app directory
WORKDIR /app

# Create non-root user
RUN useradd --create-home --shell /bin/bash app

# Copy requirements and install dependencies
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copy application code
COPY . .

# Set permissions
RUN chown -R app:app /app

# Switch to non-root user
USER app

# Expose port (Railway will override this with $PORT)
EXPOSE 8080

# One worker and two threads: a sheet write can run while /health still answers.
CMD gunicorn app:app --bind 0.0.0.0:${PORT:-8080} --workers 1 --threads 2 --timeout 120 --graceful-timeout 20
