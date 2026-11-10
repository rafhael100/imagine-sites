# F1-87 — Gravação em estágio privado com verificação antes da entrega local

O gerador F1-72 anteriormente criava a pasta de destino e escrevia quatro arquivos um após o outro. Em caso de interrupção de processo, um revisor local poderia encontrar um **diretório incompleto**, e uma nova execução não poderia substituí-lo automaticamente.

A classe `SyntheticPrivateDraftWriter` adiciona um fluxo mais seguro para a **amostra fictícia**: valida os quatro bytes e hashes antes de tocar no disco, cria uma pasta temporária privada e imprevisível no mesmo filesystem, escreve todos os arquivos com modo `0600`, reabre o diretório por `SyntheticPrivateDraftVerifier::verifyDirectory`, compara a prova calculada ao pacote em memória e só então executa `rename` para o destino final. O pacote é novamente conferido no destino. Falhas antes do movimento removem os arquivos temporários; um diretório final existente, symlink, nome fora da lista de variantes e bytes adulterados são rejeitados sem sobrescrita.

O comando Laravel `sites:assemble-draft-fixture` foi atualizado para usar essa rotina, mantendo a exigência de `APP_ENV=testing`, PHP CLI e `SITES_SYNTHETIC_DRAFT=1`. Sua saída permanece `NOT DELIVERED`, revisão interna pendente e `publishable=false`.

Os testes Laravel exercitam escrita e releitura exatas, reexecução sem sobrescrita, nome de destino arbitrário proibido, HTML alterado recusado sem criar uma saída parcial e limpeza das pastas temporárias. O F1-86 continua oferecendo conferência independente posterior.

**Limites:** renomear uma pasta no mesmo filesystem é uma operação atômica local, mas isso **não prova exclusão mútua entre escritores maliciosos**, integridade de um storage remoto, assinatura, workflow real, aprovação oficial do cliente, segurança de serviço web ou publicação. Este recurso não é endpoint nem API de upload. Auditoria independente, IAM/Portal, banco operacional, CRM, VPS, Cloudflare e os dez gates continuam pendentes e `BLOCKED`.
