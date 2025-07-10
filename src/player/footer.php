<?php







echo '    <footer class="footer">' . "\r\n\t\t" . '<div class="container">' . "\r\n\t\t\t" . '<div class="row">' . "\r\n\t\t\t\t" . '<div class="col-12">' . "\r\n\t\t\t\t\t" . '<div class="footer__copyright">' . "\r\n" . '                        &copy; 2025 <img height="20px" style="padding-left: 10px; padding-right: 10px; margin-top: -2px;" src="img/logo.png"> v';
echo $_VERSION;
echo "\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t" . '</div>' . "\r\n\t\t\t" . '</div>' . "\r\n\t\t" . '</div>' . "\r\n\t" . '</footer>' . "\r\n\t" . '<script src="./js/jquery-3.5.1.min.js"></script>' . "\r\n\t" . '<script src="./js/bootstrap.bundle.min.js"></script>' . "\r\n\t" . '<script src="./js/owl.carousel.min.js"></script>' . "\r\n\t" . '<script src="./js/jquery.mousewheel.min.js"></script>' . "\r\n\t" . '<script src="./js/jquery.mcustomscrollbar.min.js"></script>' . "\r\n\t" . '<script src="./js/wnumb.js"></script>' . "\r\n\t" . '<script src="./js/nouislider.min.js"></script>' . "\r\n\t" . '<script src="./js/jquery.morelines.min.js"></script>' . "\r\n\t" . '<script src="./js/photoswipe.min.js"></script>' . "\r\n\t" . '<script src="./js/photoswipe-ui-default.min.js"></script>' . "\r\n" . '    <script src="./js/glightbox.min.js"></script>' . "\r\n" . '    <script src="./js/jBox.all.min.js"></script>' . "\r\n" . '    <script src="./js/select2.min.js"></script>' . "\r\n" . '    <script src="./js/jwplayer.js"></script>' . "\r\n" . '    <script src="./js/jwplayer.core.controls.js"></script>' . "\r\n" . '    <script src="./js/provider.hlsjs.js"></script>' . "\r\n\t" . '<script src="./js/main.js"></script>' . "\r\n" . '    <script>' . "\r\n" . '    $(document).ready(function () {' . "\r\n" . '        ';

if ($_PAGE != 'profile') {
	echo "        \$('select').select2({" . "\r\n" . '            minimumResultsForSearch: -1' . "\r\n" . '        });' . "\r\n" . '        ';
} else {
	echo "        \$('#search__select').select2({" . "\r\n" . '            minimumResultsForSearch: -1' . "\r\n" . '        });' . "\r\n" . '        ';
}

echo '        $("#search__button").click(function() {' . "\r\n" . '            var rSearch = $("#search__input").val();' . "\r\n" . '            var rOption = $("#search__select").val();' . "\r\n" . '            window.location.href = rOption + "?search=" + encodeURIComponent(rSearch);' . "\r\n" . '        });' . "\r\n" . '    });' . "\r\n" . '    ';

if ($_PAGE == 'profile') {
	echo '    function AtoZ() {' . "\r\n" . '        $("#sort_bouquet").append($("#sort_bouquet option").remove().sort(function(a, b) {' . "\r\n" . '            var at = $(a).text().toUpperCase(), bt = $(b).text().toUpperCase();' . "\r\n" . '            return (at > bt) ? 1 : ((at < bt) ? -1 : 0);' . "\r\n" . '        }));' . "\r\n" . '    }' . "\r\n" . '    function MoveUp() {' . "\r\n" . "        var rSelected = \$('#sort_bouquet option:selected');" . "\r\n" . '        if (rSelected.length) {' . "\r\n" . '            var rPrevious = rSelected.first().prev()[0];' . "\r\n" . "            if (\$(rPrevious).html() != '') {" . "\r\n" . '                rSelected.first().prev().before(rSelected);' . "\r\n" . '            }' . "\r\n" . '        }' . "\r\n" . '    }' . "\r\n" . '    function MoveDown() {' . "\r\n" . "        var rSelected = \$('#sort_bouquet option:selected');" . "\r\n" . '        if (rSelected.length) {' . "\r\n" . '            rSelected.last().next().after(rSelected);' . "\r\n" . '        }' . "\r\n" . '    }' . "\r\n" . '    function doLogout() {' . "\r\n" . '        window.location.href = "logout";' . "\r\n" . '    }' . "\r\n" . '    ' . "\r\n" . '    $(document).ready(function () {' . "\r\n" . '        $("#output_type").change(function() {' . "\r\n" . '            $("#download_type").trigger("change");' . "\r\n" . '        });' . "\r\n" . '        $("#download_type").change(function() {' . "\r\n" . '            if ($("#download_type").val()) {' . "\r\n" . '                ';
	$rURL = rtrim(CoreUtilities::getDomainName(), '/');

	if (PLATFORM == 'xc_vm') {
		echo '                rText = "';
		echo $rURL;
		echo '/playlist/';
		echo htmlentities($rUserInfo['username']);
		echo '/';
		echo htmlentities($rUserInfo['password']);
		echo "/\" + decodeURIComponent(\$('#download_type').val());" . "\r\n" . '                ';
	} else {
		echo '                rText = "';
		echo $rURL;
		echo '/get.php?username=';
		echo htmlentities($rUserInfo['username']);
		echo '&password=';
		echo htmlentities($rUserInfo['password']);
		echo "&type=\" + decodeURIComponent(\$('#download_type').val());" . "\r\n" . '                ';
	}

	if (PLATFORM != 'xc_vm') {
	} else {
		echo '                if ($("#output_type").val()) {' . "\r\n" . "                    if (rText.indexOf('?output=') != -1) {" . "\r\n" . '                        rText = rText + "&key=" + encodeURIComponent($("#output_type").val());' . "\r\n" . '                    } else {' . "\r\n" . '                        rText = rText + "?key=" + encodeURIComponent($("#output_type").val());' . "\r\n" . '                    }' . "\r\n" . '                }' . "\r\n" . '                ';
	}

	echo '                $("#download_url").val(rText);' . "\r\n" . '            } else {' . "\r\n" . '                $("#download_url").val("");' . "\r\n" . '            }' . "\r\n" . '        });' . "\r\n" . '        $("#download_type").trigger("change");' . "\r\n" . '        $("#bouquet__form").submit(function(e){' . "\r\n" . '            rOrder = [];' . "\r\n" . "            \$('#sort_bouquet option').each(function() {" . "\r\n" . '                rOrder.push($(this).val());' . "\r\n" . '            });' . "\r\n" . '            $("#bouquet_order_array").val(JSON.stringify(rOrder));' . "\r\n" . '        });' . "\r\n" . '    });' . "\r\n" . '    ';
} else {
	if ($_PAGE == 'live') {
		if (0 >= count($rStreamIDs)) {
		} else {
			echo '    window.updateTimer = null;' . "\r\n" . '    window.XC_VM = window.XC_VM || {};' . "\r\n" . '    window.XC_VM.Listings = window.XC_VM.Listings || {};' . "\r\n" . '    window.XC_VM.Listings.DefaultChannels = "';
			echo implode(',', $rStreamIDs);
			echo '";' . "\r\n" . '    ';

			if ($rFilterBy == 'epg') {
				echo '    window.XC_VM.Listings.HideEmpty = 1;' . "\r\n" . '    ';
			} else {
				echo '    window.XC_VM.Listings.HideEmpty = 0;' . "\r\n" . '    ';
			}
		}

		echo '    ' . "\r\n" . '    function setChannel(rID, rStart=null, rDuration=null) {' . "\r\n" . '        if (window.updateTimer !== null) {' . "\r\n" . '            clearTimeout(window.updateTimer);' . "\r\n" . '        }' . "\r\n" . '        ' . "\r\n" . '        $("html, body").animate({ scrollTop: 0 }, "fast");' . "\r\n" . '        if (rStart && rDuration) {' . "\r\n" . '            var rURL = "listings.php?id=" + encodeURIComponent(rID) + "&start=" + encodeURIComponent(rStart) + "&duration=" + encodeURIComponent(rDuration);' . "\r\n" . '        } else {' . "\r\n" . '            var rURL = "listings.php?id=" + encodeURIComponent(rID);' . "\r\n" . '        }' . "\r\n" . '        $.getJSON(rURL, function(rData) {' . "\r\n" . '            $("#now__playing__title").html(rData.title);' . "\r\n" . '            $("#now__playing__epg").html(rData.epg_title);' . "\r\n" . '            $("#now__playing__text").html(rData.epg_description);' . "\r\n" . '            ' . "\r\n" . '            var rPlayer = jwplayer("now__playing__player");' . "\r\n" . '            rPlayer.setup({' . "\r\n" . '                "file": rData.url,' . "\r\n" . "                \"aspectratio\": '16:9'," . "\r\n" . '                "autostart": true,' . "\r\n" . "                \"width\": '100%'," . "\r\n" . '            });' . "\r\n" . '            rPlayer.play();' . "\r\n" . '            ' . "\r\n" . '            if ($(window).width() > 768) {' . "\r\n" . '                $(".close__btn").fadeIn(250);' . "\r\n" . '            }' . "\r\n" . '            $("#now__playing__box").slideDown(250);' . "\r\n" . '            ' . "\r\n" . '            if (!rStart || !rDuration) {' . "\r\n" . '                window.updateTimer = setTimeout(updateChannel, 60000, rID);' . "\r\n" . '            }' . "\r\n" . '        });' . "\r\n" . '    }' . "\r\n" . '    ' . "\r\n" . '    function closeChannel() {' . "\r\n" . '        if (window.updateTimer !== null) {' . "\r\n" . '            clearTimeout(window.updateTimer);' . "\r\n" . '        }' . "\r\n" . '        ' . "\r\n" . '        $(".close__btn").fadeOut(250);' . "\r\n" . '        $("#now__playing__box").slideUp(250, function() {' . "\r\n" . '            $("#now__playing__title").html("';

		if (isset($rSearchBy)) {
			echo strtoupper(str_replace('"', '\\"', $rSearchBy));
		} else {
			echo 'LIVE TV';
		}

		echo '");' . "\r\n" . '            $("#now__playing__epg").html("No Programme Information...");' . "\r\n" . '            $("#now__playing__text").html("");' . "\r\n" . '            ' . "\r\n" . '            var rPlayer = jwplayer("now__playing__player");' . "\r\n" . '            rPlayer.stop();' . "\r\n" . '        });' . "\r\n" . '    }' . "\r\n" . '    ' . "\r\n" . '    function updateChannel(rID) {' . "\r\n" . '        $.getJSON("listings.php?id=" + encodeURIComponent(rID), function(rData) {' . "\r\n" . '            $("#now__playing__title").html(rData.title);' . "\r\n" . '            $("#now__playing__epg").html(rData.epg_title);' . "\r\n" . '            $("#now__playing__text").html(rData.epg_description);' . "\r\n" . '            window.updateTimer = setTimeout(updateChannel, 60000, rID);' . "\r\n" . '        });' . "\r\n" . '    }' . "\r\n\r\n" . '    $(document).ready(function () {' . "\r\n" . '        $(".filter__btn").click(function() {' . "\r\n" . "            var rSort = JSON.parse('";
		echo str_replace("'", "\\'", json_encode(array_flip($rSortArray)));
		echo "');" . "\r\n" . '            ';
		$rCategories = array();

		foreach (CoreUtilities::getOrderedCategories($rUserInfo['category_ids'], $_PAGE) as $rCategory) {
			$rCategories[$rCategory['title']] = $rCategory['id'];
		}
		echo "            var rFilter = JSON.parse('";
		echo str_replace("'", "\\'", json_encode(array_flip($rFilterArray)));
		echo "');" . "\r\n" . "            var rCategories = JSON.parse('";
		echo str_replace("'", "\\'", json_encode($rCategories));
		echo "');" . "\r\n" . "            window.location.href = './";
		echo $_PAGE;
		echo "?sort=' + rSort[\$(\"#filter__sort input\").val()] + \"&category=\" + rCategories[\$(\"#filter__genre input\").val()] + \"&filter=\" + rFilter[\$(\"#filter__filter input\").val()];" . "\r\n" . '        });' . "\r\n" . '        ' . "\r\n" . '        $(".clear__btn").click(function() {' . "\r\n" . "            window.location.href = './";
		echo $_PAGE;
		echo "';" . "\r\n" . '        });' . "\r\n" . '    });' . "\r\n" . '    ';
	} else {
		if ($_PAGE == 'movie' || $_PAGE == 'episodes') {
			echo '    window.currentID = 0;' . "\r\n" . "    window.rURLs = \$.parseJSON('";
			echo str_replace("'", "\\'", json_encode($rURLs));
			echo "');" . "\r\n" . "    window.rSubtitles = \$.parseJSON('";
			echo str_replace("'", "\\'", json_encode($rSubtitles));
			echo "');" . "\r\n" . '    ' . "\r\n" . '    ';

			if ($_PAGE != 'episodes') {
			} else {
				if ($rLegacy) {
					echo '    function createSubtitle(rSubtitle) {' . "\r\n" . '        console.log(rSubtitle);' . "\r\n" . '        rTrack = document.createElement("track");' . "\r\n" . '        rTrack.kind = "subtitles";' . "\r\n" . '        rTrack.label = rSubtitle.label;' . "\r\n" . '        rTrack.src = rSubtitle.file;' . "\r\n" . '        $("video").append(rTrack);' . "\r\n" . '    }' . "\r\n" . '    ' . "\r\n" . '    function openPlayer(rID = 0) {' . "\r\n" . '        if (!$(".filter__btn").hasClass("disabled")) {' . "\r\n" . '            window.currentID = rID;' . "\r\n" . '            $("video source").last().attr("src", window.rURLs[rID]);' . "\r\n" . '            $("video track").remove();' . "\r\n" . '            for (rSubtitle in window.rSubtitles[rID]) {' . "\r\n" . '                createSubtitle(window.rSubtitles[rID][rSubtitle]);' . "\r\n" . '            }' . "\r\n" . '            $("video")[0].load();' . "\r\n" . '            $("video")[0].play();' . "\r\n" . '            $("#player_row").slideDown(250, function() {' . "\r\n" . "                \$('html,body').animate({" . "\r\n" . '                    scrollTop: $("#player_row").offset().top - 100' . "\r\n" . '                });' . "\r\n" . '            });' . "\r\n" . '        }' . "\r\n" . '    }' . "\r\n" . '    ';
				} else {
					echo '    function openPlayer(rID = 0) {' . "\r\n" . '        window.currentID = rID;' . "\r\n" . '        var rPlayer = jwplayer("now__playing__player");' . "\r\n" . '        rPlayer.setup({' . "\r\n" . '            "file": window.rURLs[rID],' . "\r\n" . "            \"aspectratio\": '16:9'," . "\r\n" . '            "autostart": true,' . "\r\n" . "            \"width\": '100%'," . "\r\n" . '            "tracks": window.rSubtitles[rID]' . "\r\n" . '        });' . "\r\n" . "        rPlayer.on('error', showError);" . "\r\n" . '        rPlayer.play();' . "\r\n" . '        $("#player_row").slideDown(250, function() {' . "\r\n" . "            \$('html,body').animate({" . "\r\n" . '                scrollTop: $("#player_row").offset().top - 100' . "\r\n" . '            });' . "\r\n" . '        });' . "\r\n" . '    }' . "\r\n" . '    ';
				}
			}

			echo '    ' . "\r\n" . '    ';

			if ($rLegacy) {
			} else {
				echo '    function showError(rError) {' . "\r\n" . '        ';

				if ($_PAGE == 'movie') {
					echo '        $("#player_row").slideUp(250);' . "\r\n" . "        \$('html,body').animate({" . "\r\n" . '            scrollTop: 0' . "\r\n" . '        });' . "\r\n" . '        $("#player__error").html("Error " + rError.code + " - " + rError.message);' . "\r\n" . '        $("#player__error").slideDown(250);' . "\r\n" . '        ';
				} else {
					echo '        $("#episode_" + window.currentID).addClass("disabled");' . "\r\n" . '        $("#episode_" + window.currentID + " ul").show();' . "\r\n" . '        $("#player_row").slideUp(250, function() {' . "\r\n" . "            \$('html,body').animate({" . "\r\n" . '                scrollTop: $(".seasons").offset().top - 90' . "\r\n" . '            });' . "\r\n" . '        });' . "\r\n" . '        ';
				}

				echo '    }' . "\r\n" . '    ';
			}

			echo '    ' . "\r\n" . '    $(document).ready(function () {' . "\r\n" . '        ';

			if ($rLegacy) {
				echo "        \$('video source').last().on('error', function() {" . "\r\n" . '            ';

				if ($_PAGE == 'movie') {
					echo '            $("#player__error").html("Error - This movie is currently unavailable. Please try again later.");' . "\r\n" . '            $("#player__error").slideDown(250);' . "\r\n" . '            $("#player_row").slideUp(250);' . "\r\n" . "            \$('html,body').animate({" . "\r\n" . '                scrollTop: 0' . "\r\n" . '            });' . "\r\n" . '            ';
				} else {
					echo '            $("#episode_" + window.currentID).addClass("disabled");' . "\r\n" . '            $("#episode_" + window.currentID + " ul").show();' . "\r\n" . '            $("#player_row").slideUp(250, function() {' . "\r\n" . "                \$('html,body').animate({" . "\r\n" . '                    scrollTop: $(".seasons").offset().top - 90' . "\r\n" . '                });' . "\r\n" . '            });' . "\r\n" . '            ';
				}

				echo '        });' . "\r\n" . '        ';
			} else {
				if ($_PAGE != 'movie') {
				} else {
					echo '        var rPlayer = jwplayer("now__playing__player");' . "\r\n" . '        rPlayer.setup({' . "\r\n" . '            "file": window.rURLs[0],' . "\r\n" . "            \"aspectratio\": '16:9'," . "\r\n" . '            "autostart": true,' . "\r\n" . "            \"width\": '100%'," . "\r\n" . '            "tracks": window.rSubtitles[0]' . "\r\n" . '        });' . "\r\n" . "        rPlayer.on('error', showError);" . "\r\n" . '        rPlayer.play();' . "\r\n" . '        ';
				}
			}

			if ($_PAGE != 'episodes') {
			} else {
				echo '        $("#season__select").change(function() {' . "\r\n" . '            var rSplit = $(this).val().split(" ");' . "\r\n" . '            window.location.href = "episodes?id=';
				echo intval($rSeries['id']);
				echo '&season=" + rSplit[rSplit.length-1];' . "\r\n" . '        });' . "\r\n" . '        ';
			}

			if (!isset(CoreUtilities::$rRequest['season'])) {
			} else {
				echo "        \$('html,body').animate({" . "\r\n" . '            scrollTop: $(".seasons").offset().top - 90' . "\r\n" . '        });' . "\r\n" . '        ';
			}

			echo '    });' . "\r\n" . '    ';
		} else {
			if (!($_PAGE == 'movies' || $_PAGE == 'series')) {
			} else {
				echo '    $(document).ready(function () {' . "\r\n" . '        $(".filter__btn").click(function() {' . "\r\n" . "            var rSort = JSON.parse('";
				echo str_replace("'", "\\'", json_encode(array_flip($rSortArray)));
				echo "');" . "\r\n" . '            ';
				$rCategories = array();

				foreach (CoreUtilities::getOrderedCategories($rUserInfo['category_ids'], ($_PAGE == 'movies' ? 'movie' : 'series')) as $rCategory) {
					$rCategories[$rCategory['title']] = $rCategory['id'];
				}
				echo "            var rCategories = JSON.parse('";
				echo str_replace("'", "\\'", json_encode($rCategories));
				echo "');" . "\r\n" . "            window.location.href = './";
				echo $_PAGE;
				echo "?sort=' + rSort[\$(\"#filter__sort input\").val()] + \"&category=\" + rCategories[\$(\"#filter__genre input\").val()] + \"&rating_s=\" + encodeURIComponent(\$(\"#filter__rating-start\").html()) + \"&rating_e=\" + encodeURIComponent(\$(\"#filter__rating-end\").html()) + \"&year_s=\" + encodeURIComponent(\$(\"#filter__years-start\").html()) + \"&year_e=\" + encodeURIComponent(\$(\"#filter__years-end\").html());" . "\r\n" . '        });' . "\r\n" . '        ' . "\r\n" . '        $(".clear__btn").click(function() {' . "\r\n" . "            window.location.href = './";
				echo $_PAGE;
				echo "';" . "\r\n" . '        });' . "\r\n" . '    });' . "\r\n" . '    ' . "\r\n" . '    function initYearSlider() {' . "\r\n\t\t" . "if (\$('#filter__years').length) {" . "\r\n\t\t\t" . "var firstSlider = document.getElementById('filter__years');" . "\r\n" . '            var d = new Date();' . "\r\n" . '            var rYear = d.getFullYear();' . "\r\n\t\t\t" . 'noUiSlider.create(firstSlider, {' . "\r\n\t\t\t\t" . 'range: {' . "\r\n\t\t\t\t\t" . "'min': 1900," . "\r\n\t\t\t\t\t" . "'max': rYear" . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'step: 1,' . "\r\n\t\t\t\t" . 'connect: true,' . "\r\n\t\t\t\t" . 'start: [';
				echo $rYearStart;
				echo ', ';
				echo $rYearEnd;
				echo '],' . "\r\n\t\t\t\t" . 'format: wNumb({' . "\r\n\t\t\t\t\t" . 'decimals: 0,' . "\r\n\t\t\t\t" . '})' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . 'var firstValues = [' . "\r\n\t\t\t\t" . "document.getElementById('filter__years-start')," . "\r\n\t\t\t\t" . "document.getElementById('filter__years-end')" . "\r\n\t\t\t" . '];' . "\r\n\t\t\t" . "firstSlider.noUiSlider.on('update', function( values, handle ) {" . "\r\n\t\t\t\t" . 'firstValues[handle].innerHTML = values[handle];' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '} else {' . "\r\n\t\t\t" . 'return false;' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'return false;' . "\r\n\t" . '}' . "\r\n\t" . "\$(window).on('load', initYearSlider());" . "\r\n" . '    ' . "\r\n" . '    function initRatingSlider() {' . "\r\n\t\t" . "if (\$('#filter__rating').length) {" . "\r\n\t\t\t" . "var secondSlider = document.getElementById('filter__rating');" . "\r\n\t\t\t" . 'noUiSlider.create(secondSlider, {' . "\r\n\t\t\t\t" . 'range: {' . "\r\n\t\t\t\t\t" . "'min': 0," . "\r\n\t\t\t\t\t" . "'max': 10" . "\r\n\t\t\t\t" . '},' . "\r\n" . '                ';

				if ($_PAGE == 'movies') {
					echo "\t\t\t\t" . 'step: 0.5,' . "\r\n" . '                ';
				} else {
					echo '                step: 1,' . "\r\n" . '                ';
				}

				echo "\t\t\t\t" . 'connect: true,' . "\r\n\t\t\t\t" . 'start: [';
				echo $rRatingStart;
				echo ', ';
				echo $rRatingEnd;
				echo '],' . "\r\n\t\t\t\t" . 'format: wNumb({' . "\r\n\t\t\t\t\t" . 'decimals: 1,' . "\r\n\t\t\t\t" . '})' . "\r\n\t\t\t" . '});' . "\r\n\r\n\t\t\t" . 'var secondValues = [' . "\r\n\t\t\t\t" . "document.getElementById('filter__rating-start')," . "\r\n\t\t\t\t" . "document.getElementById('filter__rating-end')" . "\r\n\t\t\t" . '];' . "\r\n\r\n\t\t\t" . "secondSlider.noUiSlider.on('update', function( values, handle ) {" . "\r\n\t\t\t\t" . 'secondValues[handle].innerHTML = values[handle];' . "\r\n\t\t\t" . '});' . "\r\n\r\n\t\t\t" . "\$('.filter__item-menu--range').on('click.bs.dropdown', function (e) {" . "\r\n\t\t\t\t" . 'e.stopPropagation();' . "\r\n\t\t\t\t" . 'e.preventDefault();' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '} else {' . "\r\n\t\t\t" . 'return false;' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'return false;' . "\r\n\t" . '}' . "\r\n\t" . "\$(window).on('load', initRatingSlider());" . "\r\n" . '    ';
			}
		}
	}
}

echo '    </script>' . "\r\n" . '    ';

if (!($_PAGE == 'live' && 0 < count($rStreamIDs))) {
} else {
	echo '    <script src="./js/listings.js"></script>' . "\r\n" . '    ';
}


echo '</body>' . "\r\n" . '</html>';
