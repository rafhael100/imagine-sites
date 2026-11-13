# F1-341 — Compose de laboratório sem escape de host ou execução arbitrária

**Escopo:** apenas o Docker Compose descartável usado nos testes sintéticos do Imagine Sites. Não é configuração de produção ou validação da VPS Leaseweb.

## Problema identificado

O verificador F1-74 já negava publicação de portas, rede externa, bind mounts não aprovados, contêiner privilegiado e falta de `no-new-privileges`. Porém, configurações do Compose podem alterar segurança **sem publicar portas**: `pid: host`, `ipc: host`, `user: 0:0`, `entrypoint`/`command` substituindo o processo de testes, `env_file` com credenciais locais, `volumes_from`, `group_add` e `sysctls`.

## Alteração

`ci/f74_docker_lab_boundary.py` passa a negar explicitamente esses campos e a exigir que `security_opt` seja **exatamente** `['no-new-privileges:true']` para os dois serviços do laboratório, sem aceitar `seccomp=unconfined` ou outros valores adicionais. Os campos anteriormente proibidos permanecem bloqueados. Foram acrescentados testes negativos, um para cada variação maliciosa.

O guard é executado pelo workflow F1-83 **antes de construir/rodar contêineres** e também testa um fixture determinístico; o Compose legítimo mantém os dois serviços, rede isolada interna, perfil opt-in `lab`, nenhum mapeamento de porta e credenciais PostgreSQL somente fictícias.

## Limitações / gates

A ausência desses campos no Compose não comprova isolamento real do kernel ou configuração da VPS. O laboratório usa imagens e dependências externas e **não** fornece homologação de rede, runtime produtivo, criptografia, RLS de clientes, autorização Portal/CRM ou implantação Cloudflare. Controles adicionais exigem plataforma, documentação de infraestrutura, AppSec, ops e auditoria humana.

Continuam obrigatórios branch protections/revisão humana (#281), homologação Leaseweb (#38) e OP01–OP05 (#33–#37). `config/operational-readiness.json` permanece `BLOCKED` com dez gates não aprovados. Sem merge/deploy, acesso à VPS, DNS, clientes ou novos custos.
