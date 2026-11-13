# F1-296 — integridade byte a byte do pacote privado de sites

**12/11/2026 · Laboratório isolado · Sem publicação**

## Lacuna fechada
O pacote de prévia sintético de Onepage/Landing Page possui dois arquivos fixos: `index.html` e `release-manifest.json`. O verificador anterior conferia identidade, bytes de HTML e manifesto, mas não aceitava o **SHA-256 externo do pacote completo** como prova adicional de integridade do transporte/armazenamento privado.

## Implementação
- `SyntheticReleaseBundle::verifyExactBundle()` confirma primeiro a gramática e o conteúdo com `verifyFiles()`, depois compara o SHA-256 do par canônico de bytes `index.html + NUL + release-manifest.json` ao digest fornecido separadamente. Recusa hash ausente, malformado ou divergente.
- O comando de laboratório `sites:package-fixture` usa a nova verificação em memória e relê os **bytes efetivamente gravados** em disco antes de confirmar sucesso.
- Testes PHP e Laravel cobrem hash válido, outro hash, truncamento, alteração de manifesto, arquivos extras, reordenação e readback local. A workflow F1-24 executa PHP 8.3/8.4 no Pull Request.

## Limite de confiança
Esta comparação detecta corrupção de um pacote contra um SHA previamente fixado. **Não autentica a origem desse SHA**, não assina releases, não verifica a Cloudflare nem comprova consentimento/aceite do cliente. Não há credenciais, dados reais, tráfego ou publicação. Só valida a preparação interna; o processo real requer identidade e aprovação do Portal, cofre, observação independente e os critérios OP01–OP05.

`config/operational-readiness.json` continua com os dez gates falsos e `status=BLOCKED`; não há merge automático em `dev/main`.
