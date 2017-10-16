(function($) {
    $(function() {
        google.charts.load("current", { packages: ["corechart", "line"] });
        sessionStorage.clear();
        var partyApiAddress = "/api/v1/history/party/" + partyCode;
        var selectedSocialMediaPlatform;
         // roughly half a year in miliseconds, not accountig for leap years;
        var HalfAyearInMS = 15778463000;
        var everySeventhDate;
        var socialMediaDates = [];
        //get data through the api


        var getData = $.get(partyApiAddress)
            .done(function(data) {
                return data;
            });

        function getCurrentDateInUnix() {
            var date = new Date(),
            year = date.getFullYear(),
            month = (date.getMonth() + 1),
            day = date.getDate()

            return Date.parse([year, month, day].join("-"));
        };

        function getExactDatesForLastSixMonths(socialMediaStats) {
            everySeventhDateCounter = 0
            var parsedDate = getCurrentDateInUnix();
            //turns the object into an array
            var socialMediaStatsArray = $.map(socialMediaStats, function(value, index) {
                return [value];
            }); 
            var statsArrayLength = socialMediaStatsArray.length -1;
            var isoDate;
            for (var i = statsArrayLength; i > 0; i--){
                isoDate = Date.parse(socialMediaStatsArray[i]["date"]);

                if ((isoDate >= (parsedDate - HalfAyearInMS)) && (everySeventhDateCounter === 0)) {
                    //converts date to iso string
                    isoDate = new Date(isoDate).toISOString();
                    //change the date into YYYY-MM-DD format, by only showing the first 10 letters
                    isoDate = isoDate.substring(0, 10);
                    socialMediaDates.push(isoDate);
                }
                everySeventhDateCounter ++;
                if (everySeventhDateCounter === 7) {
                    everySeventhDateCounter = 0;
                }

            }
            return socialMediaDates
        };

        function settingButtonCSS(selectedSocialMediaPlatform) {
            $("#" + selectedSocialMediaPlatform + "-stat").addClass("white-text blue-grey lighten-3").removeClass("white grey-text");
            //if there is no info for twitter, facebook or youtube, the area remains hidden
            $("#scm-graph-area").removeClass("hide");
        };

        function prepareSocialMediaGraphData (dataType) {
            // will contain dataset of the graph
            var dataset = [];
            var socialMediaStats = [];
            var dates = JSON.parse(sessionStorage.getItem("socialMediaDates"));
            //checks if the entry exists in sessionStorage
            if (sessionStorage.getItem(dataType) === null) {
                var stats = JSON.parse(sessionStorage.getItem("allSocialMediaStats"));
                //this creates the dataset from all the available data, filtered by date
                dates.forEach(function(currentValue) {
                    socialMediaStats.push(stats[currentValue][dataType])
                });
                sessionStorage[dataType] = JSON.stringify(socialMediaStats);
            };

            socialMediaStats = JSON.parse(sessionStorage.getItem(dataType));
            var SocialMediaType
                //this creates the dataset from all the available data, filtered by date
            dates.forEach(function(currentValue, index) {
                dataset.push([new Date(currentValue), socialMediaStats[index]]);
            });
            //checks selected dataType and readies the information needed to customize the graph
            switch (dataType) {
                case "fb-L":
                    SocialMediaType = ["likes", "Facebook Likes"]
                    break;
                case "tw-F":
                    SocialMediaType = ["followers", "Twitter followers"]
                    break;
                case "yt-S":
                    SocialMediaType = ["views", "Youtube subscribers"]
                    break;
            };
            // calls function that creates graph
            callGraphCreationFunction(dataset, SocialMediaType[0], SocialMediaType[1])
            //adds responsivness to the graph
            $(window).off("resize")
            $(window).on("resize", function() {
                callGraphCreationFunction(dataset, SocialMediaType[0], SocialMediaType[1])
            })

        };

        function callGraphCreationFunction(dataset, SocialMediaType, SocialMediaTitle) {
            google.charts.setOnLoadCallback(function() { drawBasic(dataset, SocialMediaType, SocialMediaTitle) });
            console.log("test")
        };

        // calls function that creates graphs data and changes the clicked button so that we know what is selected
        function drawBasic(dataset, SocialMediaType, SocialMediaTitle) {
            var data = new google.visualization.DataTable();
            var graphWidth;
            data.addColumn("date", "date");
            data.addColumn("number", SocialMediaType);
            data.addRows(dataset);

            var options = {
                hAxis: {
                    title: "Time"
                },
                vAxis: {
                    title: "Popularity",
                    format: "0"
                },
                legend: "none",
                'title': SocialMediaTitle,
                chartArea: {
                    left: 100,
                    right: 10
                }
            };
            var chart = new google.visualization.LineChart(document.getElementById("chart_div"));
            chart.draw(data, options);
        };

        $(".SocialMediaStatsButton").on("click", function() {
            var selectedSocialMediaPlatform = this.id;
            selectedSocialMediaPlatform  = selectedSocialMediaPlatform .slice(0, 4);
            $(".SocialMediaStatsButton").addClass("white grey-text").removeClass("white-text blue-grey lighten-3");
            $(this).addClass("white-text blue-grey lighten-3").removeClass("white grey-text");

           prepareSocialMediaGraphData(selectedSocialMediaPlatform);
        });


        $.when(getData).done(function(socialMediaStats) {
            var socialMediaDates = getExactDatesForLastSixMonths(socialMediaStats);
            sessionStorage["socialMediaDates"] = JSON.stringify(socialMediaDates);
            sessionStorage["allSocialMediaStats"] = JSON.stringify(socialMediaStats);
            socialMediaStatsPropertyName = socialMediaDates.splice(-1).pop()
            //function sets css for one of the three buttons above the graph

            //this checks if the party is present on social media
            if (socialMediaStats[socialMediaStatsPropertyName]["fb-L"] != undefined) {
                selectedSocialMediaPlatform = "fb-L";
                //sets which button should be highlighted
                settingButtonCSS(selectedSocialMediaPlatform);
                //calls the function, which creates the initial graph
                prepareSocialMediaGraphData(selectedSocialMediaPlatform);
            } else if (socialMediaStats[socialMediaStatsPropertyName]["tw-F"] != undefined) {
                selectedSocialMediaPlatform = "tw-f";
                settingButtonCSS(selectedSocialMediaPlatform);
                prepareSocialMediaGraphData(selectedSocialMediaPlatform);
            } else if (socialMediaStats[socialMediaStatsPropertyName]["yt-S"] != undefined) {
                selectedSocialMediaPlatform = "yt-S";
                settingButtonCSS(selectedSocialMediaPlatform);
                prepareSocialMediaGraphData(selectedSocialMediaPlatform);
            };
        });
    }); // end of document ready
})(jQuery); // end of jQuery name space