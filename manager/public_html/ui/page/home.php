<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <nav id="sidebarMenu" class="col-md-3 col-lg-2 d-md-block bg-light sidebar collapse">
            <div class="sidebar-sticky pt-3">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link active" aria-current="page" href="#">
                            <i class="bi bi-house-door"></i>
                            Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#">
                            <i class="bi bi-people"></i>
                            Usuários
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#">
                            <i class="bi bi-file-earmark-text"></i>
                            Conteúdo
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#">
                            <i class="bi bi-bar-chart"></i>
                            Relatórios
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#">
                            <i class="bi bi-gear"></i>
                            Configurações
                        </a>
                    </li>
                </ul>

                <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-4 mb-1 text-muted text-uppercase">
                    <span>Ferramentas</span>
                </h6>
                <ul class="nav flex-column mb-2">
                    <li class="nav-item">
                        <a class="nav-link" href="#">
                            <i class="bi bi-file-earmark-code"></i>
                            Logs
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#">
                            <i class="bi bi-database"></i>
                            Backup
                        </a>
                    </li>
                </ul>
            </div>
        </nav>

        <!-- Main Content -->
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">Dashboard</h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <div class="btn-group me-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-download"></i> Exportar
                        </button>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-calendar3"></i> Esta semana
                    </button>
                </div>
            </div>

            <!-- Cards de Estatísticas -->
            <div class="row mb-4" x-data="statsController">
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-primary shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Usuários</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800" x-text="formatNumber(stats.users)"></div>
                                </div>
                                <div class="col-auto">
                                    <i class="bi bi-people text-primary" style="font-size: 2rem;"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-success shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Conteúdos</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800" x-text="formatNumber(stats.content)"></div>
                                </div>
                                <div class="col-auto">
                                    <i class="bi bi-file-earmark-text text-success" style="font-size: 2rem;"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-info shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Visitas</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800" x-text="formatNumber(stats.visits)"></div>
                                </div>
                                <div class="col-auto">
                                    <i class="bi bi-eye text-info" style="font-size: 2rem;"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-warning shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Receita</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800" x-text="formatCurrency(stats.revenue)"></div>
                                </div>
                                <div class="col-auto">
                                    <i class="bi bi-currency-dollar text-warning" style="font-size: 2rem;"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ações Rápidas com Alpine.js -->
            <div class="row mb-4">
                <div class="col-lg-12">
                    <div class="card shadow">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Ações Rápidas</h6>
                        </div>
                        <div class="card-body">
                            <div class="row" x-data="actionsController">
                                <div class="col-md-3">
                                    <button @click="selectAction('user')" class="btn btn-primary w-100 mb-2">
                                        <i class="bi bi-person-plus"></i> Novo Usuário
                                    </button>
                                </div>
                                <div class="col-md-3">
                                    <button @click="selectAction('content')" class="btn btn-success w-100 mb-2">
                                        <i class="bi bi-file-plus"></i> Novo Conteúdo
                                    </button>
                                </div>
                                <div class="col-md-3">
                                    <button @click="selectAction('report')" class="btn btn-info w-100 mb-2">
                                        <i class="bi bi-graph-up"></i> Gerar Relatório
                                    </button>
                                </div>
                                <div class="col-md-3">
                                    <button @click="selectAction('backup')" class="btn btn-warning w-100 mb-2">
                                        <i class="bi bi-shield-check"></i> Fazer Backup
                                    </button>
                                </div>
                                <div class="col-12 mt-3" x-show="selectedAction" x-transition>
                                    <div class="alert alert-info">
                                        <strong>Ação selecionada:</strong> <span x-text="selectedAction"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabela de Dados com Alpine.js -->
            <div class="row">
                <div class="col-lg-12">
                    <div class="card shadow mb-4" x-data="usersController">
                        <div class="card-header py-3 d-flex justify-content-between align-items-center">
                            <h6 class="m-0 font-weight-bold text-primary">Últimos Usuários</h6>
                            <input type="text" class="form-control form-control-sm w-25" placeholder="Buscar..." x-model="search">
                        </div>
                        <div class="card-body">
                            <!-- Loading Spinner -->
                            <div x-show="loading" class="text-center py-5">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Carregando...</span>
                                </div>
                                <p class="mt-3 text-muted">Carregando usuários...</p>
                            </div>

                            <!-- Tabela -->
                            <div x-show="!loading" class="table-responsive">
                                <table class="table table-bordered table-hover table-sm">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>Nome</th>
                                            <th>Email</th>
                                            <th>CPF</th>
                                            <th>Telefone</th>
                                            <th>Status</th>
                                            <th>Último Acesso</th>
                                            <th>Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="user in paginatedUsers" :key="user.id">
                                            <tr :class="{ 'table-active': selectedUser === user.id }">
                                                <td x-text="user.id"></td>
                                                <td x-text="user.name"></td>
                                                <td x-text="user.email"></td>
                                                <td>
                                                    <small x-text="user.cpf"></small>
                                                </td>
                                                <td>
                                                    <small x-text="user.phone"></small>
                                                </td>
                                                <td>
                                                    <span class="badge" :class="getStatusBadgeClass(user.status)" x-text="user.status"></span>
                                                </td>
                                                <td>
                                                    <small x-text="formatDate(user.last_login)"></small>
                                                </td>
                                                <td>
                                                    <button @click="viewUser(user)" class="btn btn-sm btn-info" title="Visualizar">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <button @click="editUser(user)" class="btn btn-sm btn-primary" title="Editar">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button @click="deleteUser(user)" class="btn btn-sm btn-danger" title="Excluir">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                                <div x-show="filteredUsers.length === 0 && !loading" class="alert alert-info mt-3">
                                    Nenhum usuário encontrado.
                                </div>
                            </div>

                            <!-- Paginação -->
                            <div x-show="!loading && filteredUsers.length > 0" class="mt-4">
                                <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap">
                                    <!-- Pagination Controls -->
                                    <div class="d-flex align-items-center gap-2" x-show="itemsPerPage !== 'all'">
                                        <button class="btn btn-sm btn-outline-primary" @click="prevPage()" :disabled="currentPage === 1">
                                            <i class="bi bi-chevron-left"></i> Anterior
                                        </button>
                                        <small class="text-muted" x-text="`Página ${currentPage} de ${totalPages}`"></small>
                                        <button class="btn btn-sm btn-outline-primary" @click="nextPage()" :disabled="currentPage === totalPages">
                                            Próximo <i class="bi bi-chevron-right"></i>
                                        </button>
                                    </div>

                                    <!-- Items Per Page Select -->
                                    <div class="d-flex align-items-center gap-2">
                                        <label class="form-label mb-0"><small>Visualizar:</small></label>
                                        <select class="form-select form-select-sm" style="width: auto;" @change="setItemsPerPage($event.target.value)" :value="itemsPerPage">
                                            <option value="5">5</option>
                                            <option value="20">20</option>
                                            <option value="50">50</option>
                                            <option value="100">100</option>
                                            <option value="all">Todos</option>
                                        </select>
                                        <!-- Total Info -->
                                        <small class="text-muted" x-text="`${filteredUsers.length} usuário(s)`"></small>
                                    </div>

                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>