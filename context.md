# PJUS Help-Desk — Contexto do Sistema

> **Stack:** PHP + MySQL + Vanilla JS (SPA) | Deploy: FTP → `poc.igorvilar.com.br/pjushelp`
> **Banco de dados:** `u665104129_poc` (MySQL, Hostinger)
> **Último migrate:** `migrate2.php` (Sprint 3)

---

## 1. Arquitetura de Arquivos

```
pjushelp/
├── index.html                      SPA completo (frontend ~1400 linhas)
├── context.md                      Este arquivo
├── api/
│   ├── config.php                  Conexão DB, helpers globais
│   ├── setup.php                   Cria tabelas base + seed inicial (rodar 1x)
│   ├── migrate.php                 Sprint 2: tabelas de atendentes
│   ├── migrate2.php                Sprint 3: resolução, reabertura, relações
│   ├── auth.php                    POST login / DELETE logout / GET sessão
│   ├── departments.php             GET lista; GET ?id=x&services=1
│   ├── tickets.php                 CRUD + ações especializadas
│   ├── ticket-relations.php        Vínculos entre chamados
│   ├── tickets-search.php          Autocomplete (admin)
│   ├── resolution-categories.php   GET categorias de fechamento
│   ├── upload.php                  POST anexo
│   ├── my-queue.php                GET fila do atendente logado
│   ├── theme.php                   GET/POST white-label settings
│   └── admin/
│       ├── metrics.php             Dashboard metrics
│       ├── queue.php               Fila geral com filtros
│       ├── attendants.php          Workload + toggle disponibilidade
│       ├── reopen-settings.php     Prazo de reabertura
│       ├── priority-rules.php      CRUD regras de prioridade
│       └── services.php            CRUD serviços
└── uploads/
    └── .htaccess                   Bloqueia execução de PHP
```

---

## 2. Banco de Dados — Esquema Completo

### `departments`
| Campo | Tipo |
|---|---|
| id | INT PK AUTO |
| name | VARCHAR(100) |
| created_at | TIMESTAMP |

**Seed:** Facilities, Gente e Gestão, Tecnologia

---

### `services`
| Campo | Tipo | Notas |
|---|---|---|
| id | INT PK AUTO | |
| department_id | INT FK | → departments |
| name | VARCHAR(100) | |
| sla_hours | INT | Prazo padrão do serviço |
| created_at | TIMESTAMP | |

**Seed (10 serviços):**

| ID | Departamento | Serviço | SLA |
|---|---|---|---|
| 1 | Facilities | Manutenção predial | 48h |
| 2 | Facilities | Limpeza e conservação | 24h |
| 3 | Facilities | Controle de acesso | 8h |
| 4 | Gente e Gestão | Férias e afastamentos | 72h |
| 5 | Gente e Gestão | Benefícios | 48h |
| 6 | Gente e Gestão | Ponto e jornada | 24h |
| 7 | Tecnologia | Acesso e senha | 4h |
| 8 | Tecnologia | Equipamento | 24h |
| 9 | Tecnologia | Software e licença | 48h |
| 10 | Tecnologia | Rede e conectividade | 8h |

---

### `users`
| Campo | Tipo | Notas |
|---|---|---|
| id | INT PK AUTO | |
| name | VARCHAR(100) | |
| email | VARCHAR(150) UNIQUE | |
| password_hash | VARCHAR(255) | bcrypt |
| role | ENUM | `user`, `admin` |
| department_id | INT FK NULL | → departments |
| unit | VARCHAR(100) | Unidade/sede |
| phone_ext | VARCHAR(20) | Ramal |
| attendant_profile_id | INT FK NULL | NULL = não-atendente; preenchido = atendente |
| created_at | TIMESTAMP | |

**Seed base:**
| E-mail | Senha | Role | Perfil |
|---|---|---|---|
| admin@pjus.com.br | admin123 | admin | — (full admin) |
| usuario@pjus.com.br | user123 | user | — |

**Seed Sprint 2 (atendentes):**
| E-mail | Senha | Perfil | Limite |
|---|---|---|---|
| carlos@empresa.com | admin123 | Gerente de T.I | 5 chamados |
| ana@empresa.com | admin123 | Estagiário | 10 chamados |
| bruno@empresa.com | admin123 | Estagiário | 10 chamados |

---

### `tickets`
| Campo | Tipo | Notas |
|---|---|---|
| id | INT PK AUTO | |
| user_id | INT FK | → users (quem abriu) |
| department_id | INT FK | → departments |
| service_id | INT FK | → services |
| description | TEXT | Mín. 20 caracteres |
| status | ENUM | `open`, `in_progress`, `resolved`, `closed` |
| is_priority | TINYINT(1) | Calculado na abertura via priority_rules |
| priority_reason | VARCHAR(255) | Nome da regra que gerou a prioridade |
| sla_hours | INT | Snapshot do serviço no momento da abertura |
| sla_deadline | TIMESTAMP | `created_at + INTERVAL sla_hours HOUR` |
| sla_breached | TINYINT(1) | Atualizado a cada request por `refreshSla()` |
| resolved_at | TIMESTAMP NULL | Preenchido ao fechar |
| assigned_to | INT FK NULL | → users (atendente responsável) |
| assigned_at | TIMESTAMP NULL | Quando foi atribuído |
| assignment_type | ENUM NULL | `auto`, `manual` |
| resolution_category_id | INT FK NULL | → resolution_categories |
| resolution_notes | TEXT NULL | Obrigatório para `not_reproducible` e `duplicate` |
| duplicate_of | INT FK NULL | → tickets (chamado original, se duplicado) |
| reopen_count | INT DEFAULT 0 | Quantas vezes foi reaberto |
| last_closed_at | TIMESTAMP NULL | Data do último fechamento |
| reopen_deadline | TIMESTAMP NULL | `last_closed_at + reopen_settings.max_days_to_reopen` |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | ON UPDATE CURRENT_TIMESTAMP |

---

### `ticket_attachments`
| Campo | Tipo | Notas |
|---|---|---|
| id | INT PK AUTO | |
| ticket_id | INT FK | → tickets |
| file_name | VARCHAR(255) | Nome original |
| file_path | VARCHAR(500) | Caminho público `/pjushelp/uploads/att_xxx.ext` |
| mime_type | VARCHAR(100) | Validado via finfo |
| size_bytes | INT | |
| created_at | TIMESTAMP | |

---

### `ticket_history`
| Campo | Tipo | Notas |
|---|---|---|
| id | INT PK AUTO | |
| ticket_id | INT FK | → tickets |
| changed_by | INT FK | → users |
| field_changed | VARCHAR(100) | `status`, `is_priority`, `observation`, `assigned_to`, `resolution`, `reopen`, `relations` |
| old_value | TEXT | |
| new_value | TEXT | |
| created_at | TIMESTAMP | |

---

### `priority_rules`
| Campo | Tipo | Notas |
|---|---|---|
| id | INT PK AUTO | |
| name | VARCHAR(100) | |
| description | TEXT | |
| condition_type | ENUM | `keyword`, `department`, `service`, `manual` |
| condition_value | TEXT | Valor a verificar |
| is_active | TINYINT(1) | |
| created_at | TIMESTAMP | |

**Seed:** keyword `urgente`, service `7` (Acesso e senha), department `1` (desativado)

---

### `attendant_profiles`
| Campo | Tipo |
|---|---|
| id | INT PK AUTO |
| name | VARCHAR(100) |
| description | TEXT |
| created_at | TIMESTAMP |

**Seed:** Gerente de T.I, Estagiário

---

### `assignment_rules`
| Campo | Tipo | Notas |
|---|---|---|
| id | INT PK AUTO | |
| service_id | INT FK | → services |
| attendant_profile_id | INT FK | → attendant_profiles |
| is_active | TINYINT(1) | |
| created_at | TIMESTAMP | |

**Seed:** todos os 10 serviços mapeados (Gerente: equipamento, controle de acesso, férias; Estagiário: demais)

---

### `attendant_availability`
| Campo | Tipo | Notas |
|---|---|---|
| id | INT PK AUTO | |
| user_id | INT FK UNIQUE | → users |
| is_available | TINYINT(1) DEFAULT 1 | Toggle pelo admin |
| max_open_tickets | INT DEFAULT 10 | Limite de chamados simultâneos |
| updated_at | TIMESTAMP | ON UPDATE |

---

### `resolution_categories`
| Campo | Tipo | Notas |
|---|---|---|
| id | INT PK AUTO | |
| slug | ENUM | `resolved`, `not_reproducible`, `duplicate`, `cancelled_by_user` |
| label | VARCHAR(80) | Nome exibido na interface |
| description | TEXT | Orientação para o atendente |
| is_active | TINYINT(1) | |
| created_at | TIMESTAMP | |

**Seed:** 4 categorias (todas ativas)

---

### `reopen_settings`
| Campo | Tipo | Notas |
|---|---|---|
| id | INT PK AUTO | |
| max_days_to_reopen | INT DEFAULT 7 | Prazo em dias após o fechamento |
| updated_at | TIMESTAMP | ON UPDATE |
| updated_by | INT FK NULL | → users |

**Seed:** 1 linha com `max_days_to_reopen = 7`

---

### `ticket_relations`
| Campo | Tipo | Notas |
|---|---|---|
| id | INT PK AUTO | |
| ticket_id | INT FK | → tickets (origem) |
| related_id | INT FK | → tickets (destino) |
| relation_type | ENUM | `related`, `duplicate` |
| created_by | INT FK | → users |
| created_at | TIMESTAMP | |
| — | UNIQUE (ticket_id, related_id) | Impede vínculo duplicado |

---

### `settings` (white-label)
| Campo | Tipo |
|---|---|
| key | VARCHAR(100) PK |
| value | TEXT |
| updated_at | TIMESTAMP ON UPDATE |

**Keys:** `company_name`, `company_subtitle`, `logo_url`, `color_primary`, `color_sidebar`, `color_success`, `color_warning`, `color_danger`, `font_family`, `font_url`

---

## 3. Regras de Negócio

### Autenticação e papéis
- Sessão PHP com `session_name('HELPDESK')`, bcrypt
- `role = 'user'`: acessa só seus próprios chamados
- `role = 'admin'` sem `attendant_profile_id`: **full admin** — acessa tudo, configura o sistema
- `role = 'admin'` com `attendant_profile_id`: **atendente** — vê apenas "Minha Fila", altera só chamados atribuídos a ele

### Abertura de chamados
- `description` mínimo 20 caracteres
- SLA calculado ao abrir e nunca recalculado (`sla_hours` = snapshot do serviço)
- Prioridade avaliada automaticamente contra todas as `priority_rules` ativas (keyword, department, service)
- `sla_breached` atualizado a cada request pela função `refreshSla()` (UPDATE em lote)
- Chamado prioritário **ou** SLA vencido → linha destacada (fundo avermelhado) em todas as tabelas

### Atribuição automática (`autoAssign` em config.php)
1. Busca `assignment_rules` pelo `service_id` do chamado
2. Tenta atendente do perfil da regra com menor carga (HAVING `open_count < max_open_tickets`)
3. Fallback: perfil "Gerente de T.I"
4. Fallback final: qualquer atendente disponível
5. Se nenhum disponível: chamado fica sem `assigned_to` (destacado como "⚠ Sem responsável")
6. Desempate: menor `open_count`, depois `last_assigned` mais antigo
7. Grava `assignment_type = 'auto'` e entrada no `ticket_history`

### Reatribuição manual
- Apenas admin pode reatribuir (atendente não pode transferir chamado)
- `action=reassign` exige campo `reason` obrigatório
- Grava `assignment_type = 'manual'` e histórico com motivo

### Fechamento e categorias de resolução
- **Obrigatório** informar `resolutionCategoryId` ao fechar (via `action=resolve`)
- `resolution_notes` obrigatório (≥ 20 chars) quando slug = `not_reproducible` ou `duplicate`
- Slug `duplicate`: exige `duplicateOfId` (ID do chamado original); não pode ser o próprio chamado
- Ao fechar como `duplicate`: cria registro bidirecional em `ticket_relations` automaticamente
- Fechamento em lote: ao fechar um chamado com duplicados abertos vinculados, o modal exibe checklist para fechar todos juntos (herdam `resolution_category = duplicate`, `duplicate_of = ticket_original`)
- Ao fechar: grava `last_closed_at = NOW()` e calcula `reopen_deadline = NOW() + max_days_to_reopen DAYS`
- Histórico grava `field_changed = 'resolution'`, `new_value = categoria + notas`

### Reabertura
- Somente o **dono do chamado** pode reabrir (via `action=reopen`)
- Bloqueado se: `resolution_slug = 'duplicate'` (deve reabrir o chamado original), `NOW() > reopen_deadline`, ou ticket não é do usuário
- **Full admin** pode forçar reabertura de qualquer chamado sem verificar prazo
- Ao reabrir: `status = 'open'`, `reopen_count++`, `last_closed_at = NULL`, `reopen_deadline = NULL`
- `reason` obrigatório (≥ 20 chars), gravado em `ticket_history` com `field_changed = 'reopen'`
- Chamado volta ao **mesmo atendente** que estava atribuído

### Vínculos entre chamados
- Qualquer admin pode vincular dois chamados como `related` ou `duplicate` a qualquer momento
- Vínculos são **bidirecionais**: criar `(A→B)` cria também `(B→A)` na mesma transação
- Não é possível vincular um chamado a si mesmo (validado na API)
- UNIQUE constraint no banco impede vínculo duplicado
- Ao remover vínculo: remove ambas as direções + limpa `duplicate_of` se aplicável
- Toda criação e remoção grava entrada no `ticket_history` dos dois chamados

### SLA
- `sla_breached` vira `1` quando `NOW() > sla_deadline` e status não está fechado
- Uma vez violado, `sla_breached` não volta para `0` mesmo se o chamado for resolvido
- Visualização: badge tricolor (verde/amarelo/vermelho) baseado em horas restantes

### Uploads
- Máx. 5 arquivos por chamado, 5 MB total
- MIME validado via `finfo` (apenas image/* e application/pdf)
- Arquivos salvos em `/uploads/att_{id}_{uniqid}.{ext}`, `.htaccess` bloqueia execução de PHP

### White-label
- Tabela `settings` auto-criada no primeiro acesso a `theme.php`
- Cores aplicadas via CSS custom properties (`--primary`, `--sidebar-bg`, etc.)
- Google Fonts carregadas dinamicamente por URL
- Gráficos Chart.js leem os CSS variables no momento da renderização

---

## 4. Endpoints — Referência Completa

### Auth
| Método | Endpoint | Auth | Descrição |
|---|---|---|---|
| POST | `/api/auth.php` | — | Login; body: `{email, password}` |
| DELETE | `/api/auth.php` | logado | Logout |
| GET | `/api/auth.php` | — | Sessão atual (401 se não logado) |

### Chamados
| Método | Endpoint | Auth | Descrição |
|---|---|---|---|
| GET | `/api/tickets.php` | logado | Lista paginada; admin vê todos, user vê só os seus |
| GET | `/api/tickets.php?id=X` | logado | Detalhe + history + attachments + relations (admin) |
| POST | `/api/tickets.php` | logado | Abre chamado; chama autoAssign |
| PATCH | `/api/tickets.php?id=X` | admin | Altera status (open/in_progress), prioridade, observação |
| PATCH | `/api/tickets.php?id=X&action=assign` | admin | Atribuição manual |
| PATCH | `/api/tickets.php?id=X&action=reassign` | admin | Reatribuição com `reason` obrigatório |
| PATCH | `/api/tickets.php?id=X&action=resolve` | admin | Fecha com categoria de resolução |
| PATCH | `/api/tickets.php?id=X&action=reopen` | dono ou admin | Reabre chamado |

### Relações e busca
| Método | Endpoint | Auth | Descrição |
|---|---|---|---|
| GET | `/api/ticket-relations.php?id=X` | admin | Lista vínculos do chamado |
| POST | `/api/ticket-relations.php?id=X` | admin | Cria vínculo bidirecional; body: `{relatedId, relationType}` |
| DELETE | `/api/ticket-relations.php?id=X&relatedId=Y` | admin | Remove vínculo bidirecional |
| GET | `/api/tickets-search.php?q=&excludeId=` | admin | Autocomplete por ID ou descrição (máx 10 resultados) |
| GET | `/api/resolution-categories.php` | logado | Lista categorias ativas |

### Admin
| Método | Endpoint | Auth | Descrição |
|---|---|---|---|
| GET | `/api/admin/queue.php` | admin | Fila com filtros (status, dept, atendente, priority, breached, unassigned) |
| GET | `/api/admin/metrics.php` | admin | Cards principais (open, priority, breached, resolution_rate, reopened, reopen_rate) |
| GET | `/api/admin/metrics.php?type=monthly` | admin | Abertos × resolvidos (últimos 6 meses) |
| GET | `/api/admin/metrics.php?type=by-dept` | admin | Chamados por departamento (mês atual) |
| GET | `/api/admin/metrics.php?type=resolution-cats` | admin | Distribuição por categoria de resolução (mês atual) |
| GET | `/api/admin/metrics.php?type=quality` | admin | Qualidade por categoria/mês (últimos 6 meses) |
| GET | `/api/admin/attendants.php` | admin | Workload por atendente |
| PATCH | `/api/admin/attendants.php?id=X` | admin | Toggle `is_available`; body: `{isAvailable: bool}` |
| GET | `/api/admin/reopen-settings.php` | fullAdmin | Lê prazo de reabertura |
| PATCH | `/api/admin/reopen-settings.php` | fullAdmin | Atualiza prazo; body: `{maxDays: number}` |
| GET | `/api/admin/priority-rules.php` | admin | Lista regras |
| POST | `/api/admin/priority-rules.php` | fullAdmin | Cria regra |
| PATCH | `/api/admin/priority-rules.php?id=X` | fullAdmin | Edita/ativa/desativa |
| DELETE | `/api/admin/priority-rules.php?id=X` | fullAdmin | Remove |
| GET | `/api/admin/services.php` | admin | Lista serviços |
| POST | `/api/admin/services.php` | fullAdmin | Cria serviço |
| PATCH | `/api/admin/services.php?id=X` | fullAdmin | Edita serviço |

### Outros
| Método | Endpoint | Auth | Descrição |
|---|---|---|---|
| GET | `/api/departments.php` | logado | Lista departamentos |
| GET | `/api/departments.php?id=X&services=1` | logado | Serviços do departamento |
| POST | `/api/upload.php?ticket_id=X` | logado | Upload de anexo |
| GET | `/api/my-queue.php` | atendente | Fila do atendente logado |
| GET | `/api/theme.php` | — | Lê configurações de aparência |
| POST | `/api/theme.php` | fullAdmin | Salva configurações de aparência |

---

## 5. Frontend — Views Implementadas

### Visão Usuário (`role = 'user'`)
- **Abrir Chamado** — cards de departamento → select de serviço → textarea (mín 20 chars) → upload → modal de confirmação com #id, SLA e responsável
- **Meus Chamados** — tabela paginada; badge de status, prioridade, SLA; link para detalhe
- **Detalhe do chamado** — grade com todos os campos; seção de resolução (quando fechado); banner de reabertura contextual (pode reabrir / duplicado / expirado); histórico; anexos

### Visão Atendente (`role = 'admin'` + `attendant_profile_id`)
- **Minha Fila** — chamados atribuídos ao atendente logado; badge SLA tricolor; botões Iniciar / Resolver (abre modal de resolução) / Ver

### Visão Full Admin (`role = 'admin'` sem `attendant_profile_id`)
- **Dashboard** — 6 cards + 4 gráficos (mensal, por departamento, qualidade de resolução, pizza de categorias)
- **Fila de Chamados** — filtros por status/departamento/atendente/prioritário/SLA/sem responsável; coluna Reab. com badge âmbar; modal de gerenciamento com "Fechar Chamado" e "Forçar Reabertura"
- **Equipe** — cards por atendente com workload, média de resolução e toggle de disponibilidade
- **Regras de Prioridade** — CRUD com toggle ativo/inativo
- **Serviços** — CRUD vinculado a departamento
- **Aparência & White Label** — cores, fontes, logo, branding + configuração do prazo de reabertura

### Componentes compartilhados
- Modal de resolução: categoria obrigatória, autocomplete de chamado original (se duplicado), notas condicionais, checklist de fechamento em lote
- Seção "Chamados Relacionados" no detalhe (admin): lista vínculos, [+ Vincular] com autocomplete, remoção individual
- Toast de notificação (sucesso / erro)
- Paginação reutilizável
- Badges: status, SLA, prioridade, atribuição, perfil, categoria de resolução, reaberturas

---

## 6. Credenciais e Infraestrutura

| Item | Valor |
|---|---|
| URL | `https://poc.igorvilar.com.br/pjushelp` |
| FTP host | `31.170.166.157` |
| FTP user | `u665104129.igorvilar.com.br` |
| FTP path | `/poc/pjushelp/` |
| DB host | `localhost` |
| DB name / user | `u665104129_poc` |
| DB pass | `/1nOu&1eMY` |

---

## 7. Melhorias Futuras

- Notificação por e-mail ao atendente na atribuição
- Se serviço não tiver `assignment_rule`, sinalizar no painel admin
- Chamados atribuídos a atendente marcado como indisponível permanecem com ele (sem reatribuição automática)
- Tabela de atenção: chamados reabertos ≥ 2 vezes no dashboard admin
- CRUD de `resolution_categories` pelo admin (atualmente fixo no seed)
- CRUD de `attendant_profiles` e `assignment_rules` pelo admin
