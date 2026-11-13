# F1-57 — Seletor Classic / Modern / Warm na revisão visual interna

O motor de Onepage/Landing agora permite escolher `preset=classic|modern|warm` diretamente no painel privado **de laboratório**, mantendo a escolha ao alternar entre produto e viewport. Cada tema gera HTML com SHA-256 próprio, referência de release distinta e proposta de revisão informativa não publicável. O modo Classic preserva exatamente os bytes e `releaseRef` anteriores quando o parâmetro é omitido.

O seletor é uma navegação de links locais, sem formulário, script, JavaScript, upload ou conexão externa. A rota rejeita valores vazios, arrays, HTML, temas desconhecidos e usuários fora do processo Laravel CLI de teste. A UI deixa claro que **trocar o tema exige nova revisão interna**; esta tela não grava um workflow real e não aceita o cliente em seu nome.

Para preservar o protótipo offline F1-41 e os testes de Chromium já existentes, o exportador com `SITES_SYNTHETIC_EXPORT=1` continua gerando **quatro páginas Classic** sem links de temas. O novo teste Laravel `SyntheticReviewThemeSelectorTest` cobre as seis combinações produto/tema, SHA exato e navegação; o smoke Chromium independente F1-52 já cobre a renderização de seis variantes do motor. Os dois fluxos ainda não são um editor operacional.

A revisão F1-54 demonstra que qualquer troca real deve voltar ao estado de draft e exigir revisão interna da nova release; só o Portal pode registrar o aceite expresso do cliente. Sem produção, cliente, CRM, domínios, VPS, Cloudflare, gastos ou alterações nas branches `dev`/`main`; gates P0/RF-PEND/RV-CT/AppSec/LGPD/readiness BLOCKED. PR draft dependente da consolidação F1-56 (#77).
