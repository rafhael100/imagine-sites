# F1-179 — Navegação de âncoras da Onepage testada no Chromium

A F1-178 adicionou links locais opcionais, e a F1-179 os verifica no navegador real, especialmente na largura de 320px. O teste gera 18 páginas fictícias (Onepage × 3 paletas × 6 ordens), com manifestos SHA-256 individuais. As páginas são privadas, descartáveis e não publicáveis.

O protocolo CDP se comunica apenas com o Chromium instalado no runner. Para cada HTML, abre a página a 1365, 390 e 320px: 54 inspeções reais. Os testes exigem que os cinco links de navegação principal sejam benefícios, sobre e três links opcionais correspondentes à ordem escolhida (além do CTA separado para contato), e que CSS permita quebra de linha, sem overflow horizontal. Conferem também seções reais, âncoras locais, listas, contraste >=4,5:1, noindex, CSP, SHA-256 e zero scripts/formulários.

O recurso não habilita formulário, HTTPS, origem pública, aceitação do cliente ou tráfego de CRM. Esta automação não substitui acessibilidade testada por pessoas. OP01/02/04 e dez gates de produção ainda bloqueados; nenhum custo ou deploy.
