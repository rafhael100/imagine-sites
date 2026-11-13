# F1-177 — QA real das seis ordens de seções no Chromium

Os testes de domínio F1-175 garantem ordem e digests, e a interface F1-176 permite revisar seis disposições. Esta entrega mede se **as seis disposições de verdade cabem em telas de 1365px, 390px e 320px**, sem sobreposição, transbordamento horizontal ou texto ilegível.

O teste PHP f177-order-chrome-fixtures.php constrói 36 arquivos HTML em diretório privado descartável (2 produtos × 3 temas × 6 ordens). Todos derivam do contrato v4 fixo e respeitam os verificadores de integridade, noindex, conteúdo inerte, HTML seguro, dossiê pendente e ausência de aceite/publishing. O manifesto contém a ordem exata e SHA-256 de cada arquivo.

O programa Python usa o Chromium preinstalado no runner GitHub Actions e protocolo CDP no loopback, sem servidor HTTP. Analisa cada página nos três tamanhos: **108 inspeções reais**, verificando ordem DOM, seções FAQ/serviços/etapas presentes, listas numeradas, overflow, tamanho de cartões, legibilidade e contraste >= 4,5:1, metas de privacidade e zero formulário/script/rede.

A execução é exclusivamente de CI com dados inventados. Não é homologação humana, auditoria WCAG completa nem liberação operacional de segurança; não envia dados, PDFs, screenshots ou arquivos para fornecedores. OP01/02/04 e os dez gates de produção permanecem bloqueados, com PRs em draft e main inalterada.
