# F1-175 — Ordem controlada de seções opcionais nas Onepages e Landing Pages

O motor agora permite reorganizar visualmente a ordem de **Soluções em destaque**, **Como funciona** e **Perguntas frequentes**, usando as seis permutações possíveis das mesmas três seções estáticas. O SiteBrief valida que o array contém exatamente as três etiquetas permitidas, sem repetição, strings inválidas ou campos extras. Se alguma seção estiver ausente, não produz marcação vazia ou nova navegação.

A ordem padrão — soluções, etapas, FAQ — mantém os bytes HTML idênticos às versões anteriores. A saída permanece HTML inerte, sem scripts, links externos, formulários ou recursos de cliente.

A classe SyntheticSectionOrderRevisionPlan requer um snapshot de aprovação interna **sintético** com digest e revisão exatos, um novo releaseRef e o rótulo imagine_reviewer. Uma mudança de ordem produz SHA-256 e pacote privado novos e retorna a versão posterior para DRAFTING, sem digesto aprovado. O objeto original permanece imutável; o fluxo não autentica pessoas nem libera publicação.

Os recursos existentes de ajuste de tema e edição pontual continuam preservando a ordem escolhida. Alterações de cópia não podem incluir mudança de layout por um caminho incidental. A IA não controla o campo sectionOrder em nenhum contrato de cópia, e o editor não pode inseri-lo no changeset comum.

A suíte de teste PHP 8.3/8.4 cobre 2 produtos × 3 temas × 6 ordens, invariância sem seções, SHA-256, auditoria de segurança, revisão pendente, revogação, papéis inválidos e regressões dos contratos v1–v4.

Escopo exclusivamente de laboratório. Sem PII, HTTP público, Portal/CRM operacional, merge dev/main, deploy, VPS ou custos. OP01/OP02/OP04 e os dez gates operacionais continuam bloqueados.
