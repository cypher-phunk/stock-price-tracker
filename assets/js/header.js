document.addEventListener("DOMContentLoaded", function () {
  const headers = [
    document.querySelector("#brxe-0d4805"),
    document.querySelector("#brxe-7c3131")
  ].filter(Boolean); // Removes any nulls
  if (headers.length === 0){
    console.log("No headers found");
    return;
  }
    headers.forEach(header => {
        // add class
        header.classList.add("movie-header");
    });
});