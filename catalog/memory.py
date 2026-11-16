"""Discard transient state only after all HTTP and background work has finished."""
from __future__ import annotations

import logging
import os
import threading
from pathlib import Path

logger = logging.getLogger(__name__)
_reclaimer = None


def clear_transient_memory():
    # Call only at a quiescent boundary, never from an individual request teardown.
    from catalog import sync

    session, sync._http_session = sync._http_session, None
    sync._credentials = None
    if session is not None:
        session.close()
    sync.release_idle_memory()
    # Linux counts clean database file cache as container RAM too. This advises
    # eviction of clean pages; it neither edits files nor discards dirty writes.
    if hasattr(os, 'posix_fadvise') and hasattr(os, 'POSIX_FADV_DONTNEED'):
        for name in {'PIPELINE_DB_PATH', 'REFERENCE_STORE_PATH'}:
            value = os.getenv(name)
            if not value:
                continue
            for suffix in ('', '-wal', '-shm'):
                try:
                    fd = os.open(value + suffix, os.O_RDONLY)
                    try:
                        os.posix_fadvise(fd, 0, 0, os.POSIX_FADV_DONTNEED)
                    finally:
                        os.close(fd)
                except OSError:
                    pass


class IdleReclaimer:
    def __init__(self, app, recycle, *, delay=30, cleanup=clear_transient_memory,
                 timer_factory=threading.Timer):
        self.app, self.recycle, self.delay = app, recycle, delay
        self.cleanup, self.timer_factory = cleanup, timer_factory
        self.lock = threading.RLock()
        self.active = 0
        self.dirty = False
        self.retiring = False
        self.timer = None
        self.generation = 0

    def begin(self, *, dirty=True):
        with self.lock:
            if self.retiring:
                return False
            self.active += 1
            self.dirty |= dirty
            if dirty:
                self.generation += 1
                if self.timer is not None:
                    self.timer.cancel()
                    self.timer = None
            return True

    def end(self):
        with self.lock:
            self.active -= 1
            if self.active == 0 and self.dirty and not self.retiring and self.timer is None:
                generation = self.generation
                self.timer = self.timer_factory(self.delay, lambda: self._reset(generation))
                self.timer.daemon = True
                self.timer.start()

    def _reset(self, generation):
        with self.lock:
            if generation != self.generation or self.retiring:
                return
            self.timer = None
            if self.active:
                return  # A health read can overlap the timer; its close rearms it.
            self.retiring = True
            try:
                self.cleanup()
            except Exception:
                logger.exception('Transient cleanup failed; idle worker replacement releases its heap')
            logger.info('Idle memory reset: all HTTP and background work completed')
            self.recycle()

    def __call__(self, environ, start_response):
        if not self.begin(dirty=environ.get('PATH_INFO') != '/health'):
            start_response('503 Service Unavailable', [
                ('Content-Type', 'text/plain'), ('Retry-After', '1')])
            return [b'Worker restarting; retry shortly.']
        try:
            response = self.app(environ, start_response)
        except BaseException:
            self.end()
            raise
        return _Response(response, self.end)


class _Response:
    """Keep streaming responses protected until consumption or explicit close."""
    def __init__(self, response, finish):
        self.response, self.finish = response, finish
        self.iterator = iter(response)

    def __iter__(self):
        return self

    def __next__(self):
        try:
            return next(self.iterator)
        except BaseException:
            self.close()
            raise

    def close(self):
        if self.finish is not None:
            finish, self.finish = self.finish, None
            try:
                close = getattr(self.response, 'close', None)
                if close:
                    close()
            finally:
                finish()


def start_background(target, *, args=(), name=None):
    """Reserve memory/work protection BEFORE a new asynchronous thread starts."""
    reclaimer = _reclaimer
    if reclaimer is not None and not reclaimer.begin():
        raise RuntimeError('Worker is retiring')

    def run():
        try:
            target(*args)
        finally:
            if reclaimer is not None:
                reclaimer.end()

    thread = threading.Thread(target=run, name=name, daemon=True)
    try:
        thread.start()
    except BaseException:
        if reclaimer is not None:
            reclaimer.end()
        raise
    return thread


def install(worker):
    global _reclaimer
    if os.getenv('PIPELINE_RUNTIME_MODE') != 'on_demand' or not os.getenv('PIPELINE_DB_PATH'):
        return
    try:
        delay = max(0, min(300, int(os.getenv('PIPELINE_IDLE_RESET_SECONDS', '30'))))
    except ValueError:
        delay = 30
    if not delay:
        return

    def recycle():
        # Gunicorn drains connections and replaces only this idle worker. Its
        # master/listening socket stay available; no service restart or idle loop.
        worker.alive = False

    _reclaimer = IdleReclaimer(worker.wsgi, recycle, delay=delay)
    worker.wsgi = _reclaimer


def memory_report():
    report = {'process_id': os.getpid(), 'idle_reset_seconds': _reclaimer.delay if _reclaimer else 0}
    try:
        for line in Path('/proc/self/status').read_text().splitlines():
            name, _, value = line.partition(':')
            if name in {'VmRSS', 'RssAnon', 'RssFile'}:
                report[name + '_bytes'] = int(value.strip().split()[0]) * 1024
        stats = dict(line.split() for line in Path('/sys/fs/cgroup/memory.stat').read_text().splitlines())
        report['container_anonymous_bytes'] = int(stats['anon'])
        report['container_file_cache_bytes'] = int(stats['file'])
    except (OSError, ValueError, KeyError):
        pass
    return report
