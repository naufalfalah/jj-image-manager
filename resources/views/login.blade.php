<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Domain Image Manager - Login</title>
    <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
</head>
<body>
    <div class="header">
        <div class="header-content">
            <h1>Jome Image Manager</h1>
        </div>
    </div>

    <div class="container container-login">
        <div class="login-form">
            <h2 class="form-title">Login</h2>
            <form action="{{ route('login.post') }}" method="POST">
                @csrf
                <div class="form-group">
                    <label for="email">Email</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        class="form-input"
                        placeholder="Enter your email"
                        value="{{ old('email') }}"
                        required
                    >
                    @error('email')
                        <span class="error-message">{{ $message }}</span>
                    @enderror
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-input"
                        placeholder="Enter your password"
                        required
                    >
                    @error('password')
                        <span class="error-message">{{ $message }}</span>
                    @enderror
                </div>
                <button type="submit" class="login-btn">Login</button>
            </form>
        </div>
    </div>
</body>
</html>
