# F1-27 — Auditoria offline das prévias não publicáveis (Onepage/Landing)

**Escopo:** reforçar a validação de arquivos sintéticos F1-24 para bloquear estruturas ativas e referências externas mesmo quando um artefato HTML foi modificado e seus hashes SHA-256 foram recalculados.

## Implementação
- `InertPreviewAudit` valida exclusivamente o HTML de teste emitido pelo renderer atual, usando uma **política lexical restritiva**: tipo de produto, estrutura mínima, uma só H1, cinco metadados esperados, CSP e robots exatamente fixados, âncoras internas conhecidas, nenhuma tag desconhecida, atributos ativos, URLs externas ou CSS remoto.
- O construtor e o verificador do `SyntheticReleaseBundle` passam pela auditoria **antes** de criar ou validar o pacote.
- A suíte `tests/f27-offline-preview-audit.php` prova aprovação de uma Onepage e uma Landing Page fictícias, aceitação de texto HTML escapado e rejeição adversarial de scripts, formulários, meta refresh, stylesheet, CSS `@import/url()`, imagem remota, atributos de evento, links externos, CSP/robots adulterados, markup malformado, alteração de H1 e remoção do aviso de prévia.
- Testes incluem tentativas com o **hash do conteúdo alterado recalculado** para provar que integridade de bytes isolada não torna conteúdo seguro.
- CI PHP 8.3/8.4 sem infraestrutura, pagamentos, credenciais nem dados reais.

## Limites de segurança
Este mecanismo **não é um sanitizer HTML genérico nem prova AppSec**. Foi deliberadamente desenhado apenas para o template fixo, offline, sem formulários/terceiros e `publishable=false`. Outros layouts legítimos poderão ser rejeitados e precisam de um fluxo de revisão próprio. Um parser HTML/browser, análise de acessibilidade e segurança de produção, CSP real nos headers, testes de mobile e revisão de licenças continuam pendentes antes de expor qualquer página a clientes.

Não implementa geração real de IA, publisher Cloudflare, navegador, DNS, retenção LGPD, CRM, sinalização `ENTREGUE` do Portal nem autorização de produção. O `config/operational-readiness.json` segue BLOCKED, com `main` e VPS intocadas, AGI-11-RB01 desabilitado.

Referência: [SITES-OP03](https://github.com/rafhael100/imagine-sites/issues/35) e [SITES-OP05](https://github.com/rafhael100/imagine-sites/issues/37). Critérios de aceite permanecem abertos.
