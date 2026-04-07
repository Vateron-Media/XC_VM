<script>
    // WhatsApp Renewal Messages
    var waMessages = {
        de: "Hallo Lieber {USERNAME},\n\nIhr IPTV Abonnement endet am {EXPDATE} und es sind noch {DAYS} Tage übrig.\n\nMöchten Sie Ihr IPTV Abonnement verlängern?\n\nMit freundlichen Grüßen",
        en: "Hello Dear {USERNAME},\n\nYour IPTV subscription expires on {EXPDATE} and there are {DAYS} days remaining.\n\nWould you like to renew your IPTV subscription?\n\nBest regards",
        tr: "Merhaba Sayın {USERNAME},\n\nIPTV aboneliğiniz {EXPDATE} tarihinde sona eriyor ve {DAYS} gün kaldı.\n\nIPTV aboneliğinizi yenilemek ister misiniz?\n\nSaygılarımızla"
    };

    function updateWaPreview() {
        var lang = $("#wa_language").val();
        var username = $("#wa_username").val();
        var expdate = $("#wa_expdate").val();
        var days = $("#wa_daysremaining").val();

        var message = waMessages[lang]
            .replace("{USERNAME}", username)
            .replace("{EXPDATE}", expdate)
            .replace("{DAYS}", days);

        $("#wa_message_preview").val(message);

        var phone = $("#wa_phone").val().replace(/[^0-9]/g, '');
        var encodedMessage = encodeURIComponent(message);
        $("#wa_send_btn").attr("href", "https://wa.me/" + phone + "?text=" + encodedMessage);
    }

    function openWhatsApp(username, contact, expTimestamp) {
        if (!contact) {
            $.toast({
                heading: 'No WhatsApp Number',
                text: 'This line has no WhatsApp number set.',
                icon: 'warning',
                position: 'top-right'
            });
            return;
        }

        var expDate = expTimestamp ? new Date(expTimestamp * 1000) : null;
        var expDateStr = expDate ? expDate.toLocaleDateString('de-DE') : 'Never';
        var daysRemaining = 0;

        if (expDate) {
            var today = new Date();
            var diffTime = expDate - today;
            daysRemaining = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            if (daysRemaining < 0) daysRemaining = 0;
        }

        $("#wa_phone").val(contact);
        $("#wa_username").val(username);
        $("#wa_expdate").val(expDateStr);
        $("#wa_daysremaining").val(daysRemaining);

        updateWaPreview();
        $("#whatsappModal").modal("show");
    }

    $(document).ready(function() {
        $("#wa_language").change(function() {
            updateWaPreview();
        });
    });
</script>
