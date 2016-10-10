(function($){
  $(function(){
  		var SocialMediaDataArray = []; //global variable where we store filtered social media data
	    var partyApiAddress = "api/v1/history/party/" + partyCode; // address of the api 

		var getData = $.get( partyApiAddress ) //get data through the api
		  .done(function( data ) {	
			return data;
		});

		$.when(getData).done(function(socialMediaStats){ //data is gotten with a delay as such a promise
			var selectedDataType;
			var currentDate = new Date();
			var formatedCurrentDate = currentDate.getFullYear() + "-"+ currentDate.getMonth() + "-" + currentDate.getDay();//current date in yyyy-mm-dd
			var parsedDate = Date.parse(formatedCurrentDate); //transforms current date into unix time
			var HalfAyearInMS = 15778463000; // roughly half a year in miliseconds, not accountig for leap years;
			var objectIndex = 0;

			var socialMediaDates = [];
			for (date in socialMediaStats) { //goes though the entire object
				var isoDate = Date.parse(date); //converts the date property in the object into unix time
				if ((isoDate >= (parsedDate-HalfAyearInMS)) && (objectIndex === 0)) {  //we are only interested in data from the last half year and only one each week
					isoDate = new Date (isoDate).toISOString();//converts date to iso string
					isoDate = isoDate.substring(0,10); //change the date into YYYY-MM-DD format, by only showing the first 10 letters
					socialMediaDates.push(isoDate);
				};
				objectIndex ++;
				if (objectIndex === 7){ //when objectIndex is at 7, another week has been reached and it is reset
					objectIndex = 0;
				};
			}
			sessionStorage["socialMediaDates"]=JSON.stringify(socialMediaDates);
			sessionStorage["allSocialMediaStats"] =JSON.stringify(socialMediaStats);
			function settingButtonCSS(selectedDataType){//function sets css for one of the three buttons above the graph
				$('#'+selectedDataType+'-stat').addClass("white-text blue-grey lighten-3").removeClass("white grey-text");
				$("#scm-graph-area").removeClass("hide"); //if there is no info for twitter, facebook or youtube, the area remains hidden
			};
			if (socialMediaStats[socialMediaDates[socialMediaDates.length-1]]["fb-L"] != undefined){ //this checks if the party is present on social media
				selectedDataType = "fb-L";
				settingButtonCSS(selectedDataType); //sets which button should be highlighted
				createSocialMediaGraph(selectedDataType); //calls the function, which creates the initial graph
			} else if (socialMediaStats[socialMediaDates[socialMediaDates.length-1]]["tw-F"] != undefined){
				selectedDataType = "tw-f";
				settingButtonCSS(selectedDataType);
				createSocialMediaGraph(selectedDataType); 
			} else if (socialMediaStats[socialMediaDates[socialMediaDates.length-1]]["yt-S"] != undefined)
			{ 
				selectedDataType = "yt-S";
				settingButtonCSS(selectedDataType);
				createSocialMediaGraph(selectedDataType);
			};			
		}); 
		var createSocialMediaGraph = function (dataType){
			var dataset = []; // will contain dataset of the graph
			var socialMediaStats =[];
			var dates = JSON.parse(sessionStorage.getItem("socialMediaDates"));

			if (sessionStorage.getItem(dataType) === null) { //checks if the entry exists in sessionStorage
				var stats = JSON.parse(sessionStorage.getItem("allSocialMediaStats"));
				dates.forEach(function(currentValue){  //this creates the dataset from all the available data, filtered by date
					socialMediaStats.push(stats[currentValue][dataType])
				});
				sessionStorage[dataType] = JSON.stringify(socialMediaStats);
			};

			socialMediaStats = JSON.parse(sessionStorage.getItem(dataType));
			var SocialMediaType
			dates.forEach(function(currentValue, index){  //this creates the dataset from all the available data, filtered by date
				dataset.push([new Date(currentValue), socialMediaStats[index]]);
			});
			switch (dataType){ //checks selected dataType and readies the information needed to customize the graph
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

			google.charts.load('current', {packages: ['corechart', 'line']});
			google.charts.setOnLoadCallback(function() {drawBasic(dataset, SocialMediaType[0], SocialMediaType[1])} ); // calls function that creates graph
			
		}; 

		$( ".SocialMediaStatsButton" ).on("click", function(){ // calls function that creates graphs data and changes the clicked button so that we know what is selected
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
		    data.addColumn('date', 'date');
		    data.addColumn('number', SocialMediaType);
		    data.addRows(dataset);

			var options = {
		        hAxis: {
		          title: 'Time'
		        },
		        vAxis: {
		          title: 'Popularity',
		          format: '0'
		        },
		        legend: 'none',
		        'title': SocialMediaTitle,
		        chartArea: {
				    left: 100,
				    right: 10
				}
		    };
		    var chart = new google.visualization.LineChart(document.getElementById('chart_div'));
		    chart.draw(data, options);
		};



  }); // end of document ready
})(jQuery); // end of jQuery name space
