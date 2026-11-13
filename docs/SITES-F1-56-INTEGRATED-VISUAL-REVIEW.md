# F1-56 — Consolidação do painel visual privado no motor Imagine Sites

A cadeia de PRs F1-38..44 (#56 a #62) havia sido preparada isoladamente a partir do ramo dev. A linha atual também inclui temas visuais Classic/Modern/Warm (F1-51), revisão imutável (F1-54) e pré-checagem CRM (F1-55). Este PR reúne **código e testes de ambos** em uma só branch de revisão, sem merge ou deploy.

Arquivos de UI/QA, CI e documentação foram portados da versão testada F1-44. A rota interna do Laravel foi adicionada preservando a API sintética já existente. O exportador F1-41 foi acrescentado ao fim de `apps/sites/routes/console.php`, **sem sobrescrever o comando F1-53 de seleção de temas**. O workflow PHP integra a auditoria F1-39 e as extensões DOM/XML. O renderizador e o SiteBrief atuais foram preservados para manter exatamente o estilo Classic e os demais presets.

A interface é registrada somente com PHP CLI, `APP_ENV=testing` e flags específicas; renderiza os bytes da prévia em iframe sandbox, com digest e checklist. O exportador cria quatro páginas de demonstração e manifesto SHA-256 em diretório privado. O Chrome headless verifica teclado, largura e contraste; arquivos temporários são apagados, sem upload de capturas.

O painel consolidado usa **Classic por padrão**. A seleção Modern/Warm na tela visual será uma etapa separada depois que o CI desta integração passar. Não há editor self-service, rede externa, serviço de IA real, identidade, cliente, DNS, publicação, SSO, entrega ou contrato de aceite.

Este PR draft é empilhado no #76. PRs originais continuam abertos em rascunho, sem merge automático; esta consolidação não os fecha. Gates P0/RF-PEND/RV-CT, OP01..05, AppSec/LGPD e readiness BLOCKED.
