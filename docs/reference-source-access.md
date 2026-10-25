# ReferencePrices source recovery checkpoint

Updated 2026-10-25, Asia/Tehran. The user supplied source as `gas.rar`; Google sign-in
is no longer needed to inspect those files. Do not ask the user to export them again.

- User-associated Apps Script ID:
  `1rGG6NvN06vtZ1yigBOrNrL6FnVbGdAKlNE4YMflNxrxf-1zDFkBze7-U`.
- Editor: `https://script.google.com/home/projects/1rGG6NvN06vtZ1yigBOrNrL6FnVbGdAKlNE4YMflNxrxf-1zDFkBze7-U/edit`.
- Earlier unauthenticated fetch returned no source. The TinyFish sign-in approach was
  offered, then the user chose another sharing method. No Google login was confirmed.
- Eight unchanged JavaScript files are locally preserved with SHA-256 hashes in
  `legacy-gas/recovered-2026-10-25/`; source read, 176 top-level declarations inventoried,
  and 18 offline behavior groups reproduced. The user subsequently authorized GitHub
  preservation for future chats. Use `legacy-gas/README.md`; no repeat upload is needed
  for chats with repository access. No GAS deployment was performed.
- Audit: `recovered-gas-audit.md`. The archive is user-associated with this project;
  authenticated linkage to the editor/deployed revision was not independently verified.

## Latest clarification — D-008

The user identifies Google inference as the mechanism used with the Rezvan Pakhsh
reference source. The export's PDF importer calls Gemini using AI_API_KEY and AI_MODEL.
That implementation is already recovered. REFERENCE_REFRESH_SERVER_URL is a separate
delegation wrapper; its existence does not prove a separately configured/running helper.
Discovery of such a helper is conditional on evidence of its use, not a prerequisite
for continuing the server migration. Earlier unconditional requests for its source
were too strong. Check active path/settings and schedule without reopening the server target.

## Remaining evidence (apply the clarification above)

1. The main refresh reads `REFERENCE_REFRESH_SERVER_URL` from Script Properties and
   delegates to a separate server. The actual property value and server implementation
   are absent. Recover that service's source/configuration metadata; never request or
   publish token values. The legacy PDF path names Rezvan Pakhsh; active Store API
   source identity is not independently established by that legacy URL.
2. No `appsscript.json` or library/scope/timezone metadata is included.
3. No installed trigger list is included. Source defines daily reference refresh around
   04:00 in script timezone and a five-minute AI queue trigger. Do not assume installed.
4. No active deployment/binding list or Script Properties values are included. Check
   selected writer, mode, ownership and dependents before retiring or changing GAS.

Never execute refresh/setup/replay/mutation functions against the real project merely
to inspect it. Do not publish raw source, credentials or live supplier/customer data in
the shared handoff. The public snapshot contains sanitized audit documentation only.
