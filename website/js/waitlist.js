(function () {
    var nav = document.querySelector("[data-approvedesk-auth]");

    if (!nav || nav.childElementCount > 0) {
        return;
    }

    nav.setAttribute("data-state", "guest");
    nav.innerHTML =
        '<a href="/login" data-auth="login">Log in</a>' +
        '<a href="/register" data-auth="register">Sign up</a>';
})();
