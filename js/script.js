// Sticky Navbar

window.addEventListener("scroll",()=>{

const header=document.querySelector("header");

if(window.scrollY>80){

header.style.boxShadow="0 10px 25px rgba(0,0,0,.08)";

}

else{

header.style.boxShadow="0 2px 8px rgba(0,0,0,.05)";

}

});



// Scroll Animation

const observer=new IntersectionObserver((entries)=>{

entries.forEach(entry=>{

if(entry.isIntersecting){

entry.target.classList.add("show");

}

});

});

document.querySelectorAll("section").forEach((el)=>{

observer.observe(el);

});