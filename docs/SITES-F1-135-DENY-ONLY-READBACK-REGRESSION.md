# F1-135 — Contrato de negação da leitura de metadados de lead

## Escopo
Regressão independente de banco para os objetos sintéticos de F1-134. O arquivo `tests/f135-readback-deny-only.php` testa os oito códigos diagnósticos conhecidos e quatro métodos de capacidade em cada resultado. Mesmo `METADATA_MATCH_UNVERIFIED` nunca concede reserva, ACK ao visitante, confirmação de entrega no CRM ou retry automático.

O teste também prova que o envelope F1-131 continua sem autoridade de entrega e que o modo `SITES_F133_LEAD_PG_LAB=0` retorna `LAB_DISABLED` antes de acessar um PDO. O teste não abre conexões, não processa dados pessoais e não possui endpoint.

## Execução
```sh
php -l tests/f135-readback-deny-only.php
php tests/f135-readback-deny-only.php
```

CI: `.github/workflows/sites-f1-135-readback-deny-only.yml` em PHP 8.3 e 8.4. O teste é complementar — não substitui as provas PostgreSQL 18 com duas conexões em F1-133/134.

## Condições para produção
A leitura de uma linha técnica sintética não comprova autenticação de tenant, consentimento, recebimento físico, ACK, destino CRM autorizado nem entrega. `app.tenant_id` é GUC manipulável sob acesso arbitrário ao SQL. OP04 #36, revisão independente SEG/DBA/AppSec e os dez gates de produção permanecem bloqueados. PR em draft, sem merge dev/main, deploy, VPS, DNS, Cloudflare, PII ou despesas.
