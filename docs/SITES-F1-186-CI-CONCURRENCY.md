# F1-186 — Evitar testes repetidos em cada commit de uma PR

O desenvolvimento contínuo do Imagine Sites cria múltiplos commits por entrega e cada commit iniciava suítes repetidas, tanto por push como por pull_request. Isso consumia minutos de GitHub Actions sem oferecer cobertura adicional do último commit.

Os sete workflows alterados são Sites Engine, F1-03 Laravel, F1-41 Chromium, F1-83 Docker, F1-174 bundle editorial, F1-184 layout package e F1-185 bundle canônico. Cada um passa a usar concurrency por nome do workflow e número da PR (ou referência), cancelando execuções intermediárias obsoletas da mesma PR.

Sites Engine e Laravel F1-03 passam a executar eventos push somente na main; a verificação pull_request continua para qualquer PR. A suíte principal de PHP executa também regressões F1-169, F1-171, F1-175, F1-178 e F1-185 em PHP 8.3 e 8.4. A verificação não modifica políticas de publicação nem reduz gates de autenticação, dados reais, CRM ou infraestrutura.

O teste Python F1-186 inspeciona os sete arquivos reais e simula pelo menos 37 mutações negativas de permissão, evento, grupo, cancelamento e checkout. O objetivo é gastar menos tempo em builds antigos e manter os testes do último commit obrigatórios. Sem merge na main, VPS, DNS, Cloudflare, credenciais ou custos externos. Dez gates de produção continuam bloqueados.
