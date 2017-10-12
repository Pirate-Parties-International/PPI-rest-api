(function($) {
    $(function() {
        sessionStorage.clear();

        // address of the api 
        var partyApiAddress = "http://api.piratetimes.net/api/v1/history/party/" + partyCode;
        //get data through the api
        var getData = $.get(partyApiAddress)
            .done(function(data) {
                return data;
            });
        //data is gotten with a delay as such a promise
        $.when(getData).done(function(socialMediaStats) {
            var selectedDataType;
            var currentDate = new Date()
            //get month (becuase .getMonth() gives you a number between 0 and 11)
            var month = parseInt(currentDate.getMonth()) + 1
            //current date in yyyy-mm-dd
            var formatedCurrentDate = currentDate.getFullYear() + "-" + month + "-" + currentDate.getDate();
            //transforms current date into unix time
            var parsedDate = Date.parse(formatedCurrentDate);
            // roughly half a year in miliseconds, not accountig for leap years;
            var HalfAyearInMS = 15778463000*2;
            var objectIndex = 0;
            var socialMediaDates = [];
            var isoDate

             
            //turns the object int an array
            var socialMediaStatsArray = $.map(socialMediaStats, function(value, index) {
                return [value];
            }); 
            var statsArrayLength = socialMediaStatsArray.length -1

            //goes though the entire object
            for (var i = statsArrayLength; i > 0; i--){
                isoDate = Date.parse(socialMediaStatsArray[i]["date"]);

                if ((isoDate >= (parsedDate - HalfAyearInMS)) && (objectIndex === 0)) {
                    //converts date to iso string
                    isoDate = new Date(isoDate).toISOString();
                    //change the date into YYYY-MM-DD format, by only showing the first 10 letters
                    isoDate = isoDate.substring(0, 10);
                    socialMediaDates.push(isoDate);
                };
                objectIndex++;
                //when objectIndex is at 7, another week has been reached and it is reset
                if (objectIndex === 7) {
                    objectIndex = 0;
                };

            }
            console.log(socialMediaDates)

            sessionStorage["socialMediaDates"] = JSON.stringify(socialMediaDates);
            sessionStorage["allSocialMediaStats"] = JSON.stringify(socialMediaStats);
            //function sets css for one of the three buttons above the graph
            function settingButtonCSS(selectedDataType) {
                $("#" + selectedDataType + "-stat").addClass("white-text blue-grey lighten-3").removeClass("white grey-text");
                //if there is no info for twitter, facebook or youtube, the area remains hidden
                $("#scm-graph-area").removeClass("hide");
            };
            //this checks if the party is present on social media
            if (socialMediaStats[socialMediaDates[socialMediaDates.length - 1]]["fb-L"] != undefined) {
                selectedDataType = "fb-L";
                //sets which button should be highlighted
                settingButtonCSS(selectedDataType);
                //calls the function, which creates the initial graph
                createSocialMediaGraph(selectedDataType);
            } else if (socialMediaStats[socialMediaDates[socialMediaDates.length - 1]]["tw-F"] != undefined) {
                selectedDataType = "tw-f";
                settingButtonCSS(selectedDataType);
                createSocialMediaGraph(selectedDataType);
            } else if (socialMediaStats[socialMediaDates[socialMediaDates.length - 1]]["yt-S"] != undefined) {
                selectedDataType = "yt-S";
                settingButtonCSS(selectedDataType);
                createSocialMediaGraph(selectedDataType);
            };
        });
        var createSocialMediaGraph = function(dataType) {
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

            google.charts.load("current", { packages: ["corechart", "line"] });
            // calls function that creates graph
            google.charts.setOnLoadCallback(function() { drawBasic(dataset, SocialMediaType[0], SocialMediaType[1]) });
            //adds responsivness to the graph
            window.addEventListener("resize", function() {
                google.charts.setOnLoadCallback(function() { drawBasic(dataset, SocialMediaType[0], SocialMediaType[1]) });
            });
        };
        // calls function that creates graphs data and changes the clicked button so that we know what is selected
        $(".SocialMediaStatsButton").on("click", function() {
            var dataType = this.id;
            dataType = dataType.slice(0, 4);
            $(".SocialMediaStatsButton").addClass("white grey-text").removeClass("white-text blue-grey lighten-3");
            $(this).addClass("white-text blue-grey lighten-3").removeClass("white grey-text");

            createSocialMediaGraph(dataType);
        });
        /**
         * Draws chart from dataset 
         * @param  {array}
         * @return {null}
         */
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



    }); // end of document ready
})(jQuery); // end of jQuery name space