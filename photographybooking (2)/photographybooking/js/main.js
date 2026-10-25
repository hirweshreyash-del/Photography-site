// main.js - Simple student level helper scripts
document.addEventListener("DOMContentLoaded", () => {
    // Confirm password check for signup form
    const signupForm = document.querySelector("form[action='signup.php']");
    if (signupForm) {
        signupForm.addEventListener("submit", (e) => {
            const pass = signupForm.querySelector("input[name='password']").value;
            const confirmPass = signupForm.querySelector("input[name='confirm_password']").value;
            if (pass !== confirmPass) {
                e.preventDefault();
                alert("Passwords do not match!");
            }
        });
    }
});
