# F1-239 — screenshots desktop e celular de seis exemplos Imagine Sites

Extensão da galeria F1-238, que já disponibiliza HTMLs autocontidos. O novo script `ci/f239_demo_chromium_screenshots.py` usa **Chromium real**, presente no runner GitHub Actions, e o mesmo cliente Chrome DevTools Protocol (CDP) utilizado no QA visual F1-43.

## Resultado gerado

**Doze PNGs**, um por cada combinação de produto (`onepage`, `landing_page`), visual (`classic`, `modern`, `warm`) e dispositivo:
- desktop: **1365 × 900 pixels**;
- celular: **390 × 844 pixels**.

Cada captura é renderizada a partir dos **bytes exatos** de um dos seis HTMLs F1-238 auditados. O navegador recebe o conteúdo via `Page.setDocumentContent` em `about:blank`, sem navegar a URLs de clientes ou fazer downloads externos. Antes de salvar, o script verifica que a página tem título H1, viewport correta, `noindex`, nenhum elemento ativo ou estouro horizontal. Os PNGs têm assinatura, largura, altura, tamanho mínimo e SHA-256 conferidos. Um arquivo `screenshot-manifest.json` relaciona os 12 screenshots ao hash do manifesto de origem.

## Acesso

No GitHub Actions, abra o workflow **Sites F1-239 real desktop and mobile screenshots of fictional demos** e baixe o artifact `imagine-sites-demo-screenshots-fictional`. O prazo de armazenamento é **7 dias**. Os arquivos são apenas demonstrações do fictício Aurora Exemplo, não sites de clientes nem autorização de publicação.

O processo testa acesso ao próprio Chrome via loopback; nenhum endpoint público, servidor, formulário, conta, domínio, conexão CRM, token, n8n, Cloudflare ou VPS é utilizado. O CI remove os arquivos do runner após o upload.

**Escopo técnico:** capturas e validações estruturais de 12 viewports. Isto não é certificação WCAG, análise editorial humana nem homologação de performance em produção. Os gates operacionais permanecem BLOQUEADOS.
