# F1-345 — limites de recursos do laboratório Docker obrigatórios

**Laboratório descartável de CI, não sizing de VPS/produção.**

O Compose de testes já limita PostgreSQL a 512 MiB e o contêiner Laravel a 768 MiB, ambos com `pids_limit:128`. A verificação F1-74 passa a exigir esses limites também no **Compose renderizado**, aceitando apenas os equivalentes normalizados em bytes pelo Docker Compose.

Mudanças em memória, limite de PIDs, escala/replicação, `oom_kill_disable`, `ulimits`, `cpus`, `cpuset`, `memswap_limit`, `shm_size` e demais substituições não previstas falham antes de iniciar os serviços. Foram acrescentados testes negativos para limites desativados, memória maior, réplicas extras e quotas desproporcionais.

O objetivo é impedir que uma alteração de laboratório consuma recursos inesperados do runner. Esse controle **não dimensiona** a VPS, não garante ausência de DoS de runtime em containers reais, nem substitui limites definidos no orquestrador de produção. Nenhuma instância Leaseweb, cliente, Portal/CRM, Cloudflare, DNS ou custo foi modificada. Branch protections #281 e homologações OP01–OP05 ainda são obrigatórias.
