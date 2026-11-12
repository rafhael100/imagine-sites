# F1-238 — galeria navegável de demonstrações Onepage e Landing Page (artefato CI)

**Entrega visual para revisão, não publicação.** O workflow `sites-f1-238-demo-gallery-artifact.yml` gera **seis páginas HTML reais** com o motor `StaticSiteRenderer`: Onepage e Landing Page, cada uma em Classic, Modern e Warm, usando exclusivamente o briefing fictício `Aurora Exemplo` de `fixtures/sites/f1-06-onepage.synthetic.json`.

Cada uma das seis páginas passa por dois validadores independentes: `InertPreviewAudit::isSafe()` e `SyntheticPreviewQaReport::inspect()`. O script também compara SHA-256 do artefato em memória com os bytes gravados. A galeria possui `index.html` clicável, `README.txt`, `manifest.json` e `SHA256SUMS`. O workflow reconfere todos os hashes e bloqueia qualquer página com script, formulário, URL remota ou sem `noindex`.

## Como visualizar o material

No GitHub, abra o [workflow F1-238](https://github.com/rafhael100/imagine-sites/actions/workflows/sites-f1-238-demo-gallery-artifact.yml) e o run correspondente ao PR. Em **Artifacts**, abra `imagine-sites-demo-gallery-fictional` e baixe o pacote. Extraia os arquivos e abra o `index.html` no navegador; os seis links funcionam offline. O artefato é retido por apenas **7 dias**. Um run de PHP 8.3 valida paridade; somente o de PHP 8.4 envia o pacote para evitar duas cópias.

O pacote **não inclui clientes, fotos, formulários, leads, JavaScript, chamadas de rede, senhas ou infraestrutura**. Nenhuma das páginas é um release aprovado ou pronto para indexação: todos os HTMLs permanecem `noindex` e exibem aviso de demonstração. A publicação oficial e o aceite continuam restritos às autoridades Portal/Sites e aos gates de produção.

## Limites e próximos passos

Esta é uma demonstração visual de produto que permite revisão de layout/responsividade sem liberar Cloudflare, domínio ou captação. **Não substitui** a integração real com Portal, geração IA, gestão de clientes, CRM, acessibilidade formal nem homologação em VPS. Ainda são necessárias as aprovações operacionais em #33–#38. Nenhum merge nem deploy ocorre ao produzir esse artifact do GitHub Actions.
