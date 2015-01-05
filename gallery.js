// The global variable for the current image
var image = '';

// Wait for the document to implement all hooks
$( document ).ready(function() {

    // When a gallery item is clicked, show the viewer
    $('.galleryitem').click(function(e) {
        // Prevent redirect from the link
        e.preventDefault(); 
        
        // Actually show the viewer and hide the scroll bar
        $('#photoviewer').css('display','block');
        $('html').css('overflow', 'hidden');
        
        // Show the clicked image and store the current image
        showImage(this);
        image = $(this);
    });
    
    console.log('debug start');
    
    
    // Register clicks
    $('.photoviewer-right').click( nextImage );
    $('.photoviewer-left').click( prevImage );
    
    // Hide photoviewer with a click
    $('.photoviewer-close').click(function() {
    
        // Hide the foto viewer
        $('#photoviewer').css('display','none');
        $('html').css('overflow', 'visible');
    });
});

// Register keypresses
$(document).keydown(function(e) {
    var code = e.keyCode || e.which;
    if(code == 39) { nextImage(); }
    if(code == 37) { prevImage(); }
});

// Show image in the main thingy
function showImage(object) {
    $('#photoviewer-photo').attr('src', $(object).children(":first").attr('href'));
    $('.photoviewer-name').html($(object).children(":first").children(":first").attr('alt'));
    
    $('#photoviewer-load').css('display', 'block')
    
    $('#photoviewer-photo').load(function() {
        $('#photoviewer-load').css('display', 'none')
    });
    
}

// Switch to the next image
function nextImage() {
    var next = $(image).next();
            
    if( next.attr('class') == 'galleryitem' ) {
        image = next;
    } else {
        image = $('.galleryitem').first();
    }
    showImage(image);
}

// Switch to the previous image
function prevImage() {
    var prev = $(image).prev();
            
    if( prev.attr('class') == 'galleryitem' ) {
        image = prev;
    } else {
        image = $('.galleryitem').last();
    }
    showImage(image);
}