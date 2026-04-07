---
description: "Use when editing nginx configuration files in lb_configs/ or src/infrastructure/nginx/. Covers XC_VM nginx conventions."
applyTo: "lb_configs/**"
---
# Nginx Configuration — XC_VM

## File Locations
- `lb_configs/` — LoadBalancer-specific nginx configs (live.conf, nginx.conf)
- `src/infrastructure/nginx/` — Full build nginx templates (HTTP, SSL, stream proxy)

## Rules
- Always test config syntax before committing: `nginx -t`
- Use variables for upstream addresses — avoid hardcoded IPs
- Preserve existing `location` block ordering (more specific patterns first)
- SSL configs must use TLS 1.2+ only
- Do NOT add `server_tokens on` — keep server version hidden

## XC_VM-Specific
- Streaming endpoints are proxied via nginx to backend PHP processes
- LoadBalancer build has different upstream configuration than MAIN build
- Config changes may need corresponding changes in both `lb_configs/` and `src/infrastructure/nginx/`
