# Sites — integração PG18 aprovada do Portal para consumo Landing (candidata)

Branch isolada filha de Sites #557 (HEAD `acc935766ae385a04e37f29613008c8c09f6730a`), usando a HEAD imutável `3c3ba328111445394ad756b9a12507db393a62d1` da Portal #1015. Preserva #541/#542 e todas as branches anteriores.

## Prova concreta em PG18 descartável

O teste PHP executa a fixture SQL do Portal, cria um head sintético aprovado e evento de aprovação coincidente, lê por `Pg18LandingPageApprovedBriefHeadSourceCandidate` com **transações READ ONLY REPEATABLE READ**, serializa pelo encoder original Portal e consome com o decoder + revalidação de admissão reais do Sites. Compara tenant/pedido/site, contrato, versão, epoch, hash SHA-256 e bytes exatos. No meio das duas leituras, um evento de revogação é inserido de verdade no PostgreSQL efêmero: Sites deve negar a leitura e o replay subsequente. Também testa troca de tenant, versão e epoch.

O workflow PHP 8.3/8.4 não usa serviços externos, credenciais reais nem publicação. Mantém regressão independente da #557. Nenhum registro real de cliente é copiado.

## Limites de segurança

**Não é operacional.** A fonte de aprovação ainda não é oficial, a leitura positiva usa superusuário **somente na fixture CI**, a admissão simulada não comprova permissão real por recurso, a identidade do aprovador é texto sintético e o reader comum permanece sem política RLS de acesso. Duas transações de leitura são independentes e não impedem ABA/revogação após a segunda leitura. Falta o escritor auditado vinculado à identidade Portal e MFA, role RLS restrita, consulta transacional integrada de custódia + aprovação, transporte S2S/mTLS homologado, artefato e revisão Landing, publicação técnica e aceite explícito. Nenhum endpoint/app de produção foi configurado; defaults continuam negados.

Nenhum merge/deploy/alteração de main, VPS, produção, Cloudflare, DNS, credenciais ou Asaas. **Dez gates de negócio e operação seguem BLOCKED**.
