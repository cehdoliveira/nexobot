<!-- Main Content -->
<main>
    <!-- Hero Section -->
    <section class="bg-primary text-white py-5">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6">
                    <h1 class="display-4 fw-bold">Bem-vindo ao Nexo</h1>
                    <p class="lead">Uma plataforma moderna construída com as melhores tecnologias</p>
                    <button class="btn btn-light btn-lg">Saiba Mais</button>
                </div>
                <div class="col-lg-6">
                    <i class="bi bi-laptop" style="font-size: 10rem;"></i>
                </div>
            </div>
        </div>
    </section>

    <!-- Alpine.js Counter Example -->
    <section class="py-5" id="sobre">
        <div class="container">
            <h2 class="text-center mb-5">Exemplo com Alpine.js</h2>
            <div class="row justify-content-center">
                <div class="col-md-6">
                    <div class="card" x-data="counterController">
                        <div class="card-body text-center">
                            <h5 class="card-title">Contador Interativo</h5>
                            <p class="display-4" x-text="count"></p>
                            <div class="btn-group" role="group">
                                <button @click="decrement()" class="btn btn-danger">-</button>
                                <button @click="reset()" class="btn btn-secondary">Reset</button>
                                <button @click="increment()" class="btn btn-success">+</button>
                            </div>
                            <div class="mt-3">
                                <button @click="toggle()" class="btn btn-primary">
                                    <span x-text="open ? 'Ocultar' : 'Mostrar'"></span> Detalhes
                                </button>
                                <div x-show="open" x-transition class="alert alert-info mt-3">
                                    Este é um exemplo de Alpine.js funcionando com Bootstrap!
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Services Section -->
    <section class="bg-light py-5" id="servicos">
        <div class="container">
            <h2 class="text-center mb-5">Nossos Serviços</h2>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="card h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-code-square text-primary" style="font-size: 3rem;"></i>
                            <h5 class="card-title mt-3">Desenvolvimento</h5>
                            <p class="card-text">Soluções web modernas e responsivas</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-palette text-success" style="font-size: 3rem;"></i>
                            <h5 class="card-title mt-3">Design</h5>
                            <p class="card-text">Interfaces intuitivas e atraentes</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-gear text-warning" style="font-size: 3rem;"></i>
                            <h5 class="card-title mt-3">Consultoria</h5>
                            <p class="card-text">Orientação técnica especializada</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Contact Section -->
    <section class="py-5" id="contato">
        <div class="container">
            <h2 class="text-center mb-5">Entre em Contato</h2>
            <div class="row justify-content-center">
                <div class="col-md-6">
                    <form x-data="contactController" @submit="submitForm($event)">
                        <div class="mb-3">
                            <label for="name" class="form-label">Nome</label>
                            <input type="text" class="form-control" id="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" required>
                        </div>
                        <div class="mb-3">
                            <label for="message" class="form-label">Mensagem</label>
                            <textarea class="form-control" id="message" rows="4" required></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Enviar</button>
                    </form>
                </div>
            </div>
        </div>
    </section>
</main>