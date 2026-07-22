document.addEventListener("DOMContentLoaded", () => {
  document.querySelectorAll("[data-toggle-password]").forEach((button) => {
    const target = document.querySelector(
      button.getAttribute("data-toggle-password"),
    );
    if (!target) {
      return;
    }

    button.addEventListener("click", () => {
      target.type = target.type === "password" ? "text" : "password";
      button.textContent = target.type === "password" ? "Show" : "Hide";
    });
  });

  document.querySelectorAll("a[data-profile-popup]").forEach((link) => {
    link.addEventListener("click", (event) => {
      event.preventDefault();
      const href = link.getAttribute("href");
      if (!href) {
        return;
      }

      window.open(
        href,
        "jobbridgeProfilePopup",
        "width=980,height=760,menubar=no,toolbar=no,location=no,resizable=yes,scrollbars=yes",
      );
    });
  });
});
