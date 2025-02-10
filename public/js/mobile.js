$(function() {
  console.log("File mobile.js loaded");
});

function isMobileDevice() {
  return /Android|iPhone|iPad|iPod|Opera Mini|IEMobile|WPDesktop|webOS/i.test(navigator.userAgent);
}

function isTouchDevice() {
  return window.matchMedia("(pointer: coarse)").matches;
}

function isMobile() {
  return isMobileDevice() || isTouchDevice();
}
