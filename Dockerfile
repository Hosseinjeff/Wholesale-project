# Use Python 3.11 slim as base image
FROM python:3.11-slim

# Set environment variables
ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1 \
    PORT=8080

# Install system dependencies
RUN apt-get update && apt-get install -y \
    build-essential \
    && rm -rf /var/lib/apt/lists/*

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

# Start application using gunicorn
# Use brackets for exec form which is preferred in Docker, but we need shell for $PORT
# Alternatively, Railway handles the start command from railway.json
CMD gunicorn app:app --bind 0.0.0.0:${PORT:-8080} --workers 1 --threads 8 --timeout 0
