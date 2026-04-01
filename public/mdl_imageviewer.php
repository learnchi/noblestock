<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) {
	// 直接指定での実行はできない
    http_response_code(404);
    exit;
}
?>

<!-- 使い方: data-bs-toggle="modal" data-bs-target="#imageViewer" data-bs-image="uploads/xxx.png" -->
<div class="modal fade" id="imageViewer" tabindex="-1">
	<div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-body">
        <img class="img-fluid d-block mx-auto" src=""/>
      </div>
    </div>
  </div>
</div>

<script>
const imageViewer = document.getElementById('imageViewer')
if (imageViewer) {
  imageViewer.addEventListener('show.bs.modal', event => {
    // Update the modal's content.
    const imageName = event.relatedTarget.getAttribute('data-bs-image');
	if (imageName) {
		const modalBodyInput = imageViewer.querySelector('.modal-body img')
		modalBodyInput.src = imageName;
	}
  })
}
</script>