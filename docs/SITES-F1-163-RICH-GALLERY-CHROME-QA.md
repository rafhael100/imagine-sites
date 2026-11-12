# F1-163 — Inspeção visual automatizada da galeria de revisão rica no Chromium

A F1-162 exporta 12 páginas com a interface Laravel de revisão privada contendo um iframe sandbox com a prévia real de Onepage/Landing rica (FAQ e destaques). A F1-163 executa a galeria completa num Chromium preexistente do runner GitHub Actions, sem baixar navegador nem acionar fornecedores.

O teste Python ci/f163_rich_gallery_cdp.py carrega cada arquivo somente no documento CDP local (sem servidor público) e verifica dois contextos: a interface externa do revisor e os bytes exatos do HTML de site dentro do iframe. Para cada um dos 12 arquivos, verifica a tela em 1365, 390 e 320 pixels, totalizando 72 medições independentes de viewport (12 × 3 × 2).

A suíte confere SHA-256 dos arquivos e do artefato dentro do iframe, vínculos exclusivamente locais entre variantes, sandbox vazio, ausência de controles executáveis, noindex/CSP, estados de aceite pendente, seletor visual, títulos h1/h3, layout responsivo sem overflow, quebra de texto e contraste de parágrafos e cabeçalhos estáticos igual ou superior a 4,5:1.

A execução é apenas uma evidência técnica. Não representa certificação WCAG completa nem revisão humana independente. Não existe acesso a clientes, hospedagem, endpoints públicos, fornecedores pagos, IA ou CRM. As restrições de publicação e os 10 gates de produção continuam inalterados.
