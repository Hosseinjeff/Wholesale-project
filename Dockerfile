# syntax=docker/dockerfile:1
# Use Python 3.11 slim as base image
FROM python:3.11-slim

# One glibc arena, and return freed pages, so an idle worker stays small.
ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1 \
    MALLOC_ARENA_MAX=2 \
    MALLOC_TRIM_THRESHOLD_=131072 \
    HOME=/home/app \
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
COPY container-entrypoint.sh /usr/local/bin/molavi-entrypoint

# Set permissions
RUN chown -R app:app /app && chmod 755 /usr/local/bin/molavi-entrypoint

# Expose port (Railway will override this with $PORT)
EXPOSE 8080

# One Gunicorn worker; WordPress schedules idle work so Railway can sleep.
CMD ["/usr/local/bin/molavi-entrypoint"]
