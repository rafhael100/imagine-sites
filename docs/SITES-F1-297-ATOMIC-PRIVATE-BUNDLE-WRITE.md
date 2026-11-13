# Imagine Sites F1-297 — gravação atômica do pacote privado

**12/11/2026 | Laboratório CLI exclusivamente sintético | Sem publicação**

## Motivação
O comando de laboratório `sites:package-fixture` criava a pasta de destino e escrevia `index.html` e `release-manifest.json` nela em sequência. Uma falha entre essas gravações poderia deixar um pacote parcialmente atualizado no caminho de saída privado.

## Alterações
1. Exigir caminho privado sem links simbólicos, e recusar destino que já exista (sem sobrescrever versão anteriormente verificada).
2. Criar pasta temporária aleatória no **mesmo diretório privado**, em modo 0700; escrever arquivos em 0600.
3. Reler ambos os arquivos na pasta temporária e conferir gramática, SHA do HTML e SHA do pacote exato com `verifyExactBundle` (F1-296).
4. Usar uma renomeação única da pasta temporária para o destino de laboratório depois da verificação. Em falha, excluir apenas arquivos fixos do staging próprio.
5. Manter o comando restrito a PHP CLI em ambiente de teste com opt-in, sem upload, HTTP real, DNS, CRM, Portal ou Cloudflare.

## Limitações
A renomeação no mesmo filesystem torna a **visibilidade da pasta** atômica para os testes, mas não substitui gravação durável com fsync, controle de concorrência de produção, storage externo ou assinatura confiável. A existência do arquivo é apenas estado sintético de QA, não liberação. Publicação e aceite permanecem bloqueados.

CI: suíte F1-24 em PHP 8.3/8.4, Laravel e testes de reexecução sem sobrescrita. Issue OP03 #35 e proteção de branches #281 continuam pendentes, sem merge/deploy.
