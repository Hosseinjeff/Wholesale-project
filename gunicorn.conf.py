"""One-shot idle heap reclamation; no periodic timer on a fresh idle worker."""
def post_worker_init(worker):
    from catalog.memory import install
    install(worker)
