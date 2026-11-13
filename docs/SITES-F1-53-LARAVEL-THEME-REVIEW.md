# F1-53 — Seleção de tema no fluxo privado de revisão Laravel

O comando de laboratório F1-37 agora admite o parâmetro opcional `preset`: `APP_ENV=testing SITES_SYNTHETIC_REVIEW=1 php artisan sites:review-fixture onepage modern`.

Produtos disponíveis: `onepage` e `landing_page`. Presets permitidos: `classic`, `modern` e `warm`. Marca e produto são escolhidos por fonte de teste controlada, não pelo texto sugerido pelo gerador. O tema é uma enumeração fixa do motor F1-51 — nunca CSS livre, script ou fonte externa.

O `classic` conserva **nome de diretório, releaseRef e bytes HTML** das chamadas antigas. Para outros temas, o nome da pasta privada e a referência da release recebem sufixo `-modern` ou `-warm`, preservando revisão e SHA-256 exatos. O pacote permanece `publishable=false`, `officialClientAcceptance=false` e só pode ser construído em CLI/testing com flag.

A CI reaproveita o workflow Laravel F1-37, com testes em PHP 8.3/8.4 para as seis combinações, SHA exato, temas ilegais, falta de flag, compatibilidade com chamada original e proibição de sobrescrever destino. A regra do Portal sobre aceite e entrega não muda.

Não há website público, n8n, modelo de IA real, CRM, leads, domínios ou aprovação de cliente. A integração dos seletor de tema à UI do Portal fica para a fase autenticada, após resolvidos os gates P0/RF-PEND/RV-CT/AppSec/LGPD e identidade. Branch main/dev e readiness mantidos intactos.
