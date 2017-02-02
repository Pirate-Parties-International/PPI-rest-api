(function($) {
    var app = angular.module('app', ['infinite-scroll']); 

    app.controller('postController', ['$scope', 'pictureAndPostFactory', function($scope, pictureAndPostFactory) {
        //URL that gets all socialmedia posts that are (primarily) pictures
        $scope.data =[]; //stores past and currenty visible data
        $scope.masterArray =[]; //stores al the data
        $scope.originalArray =[]; // The purpose of this array is to store the original layout of the masterArray
        $scope.address = { //object contains all possible filters for the API
            subType: "T",
            socialPlatform: "",
            sort: "",
            partyCode: "",
            offset: 0
        };
        //sets default checked radio button when the page loads 
        $scope.radioBtn = "all";

        pictureAndPostFactory.partyList().then(function(successResponse){
            $scope.partyList = Object.values(successResponse)
            $scope.partyListObject = successResponse;
        });      
        
        //this functions loads more data for the infinite scroll
        // it constantily updates the array from which ng-repeat gets its data
        $scope.loadData = function() {
            //Function that gets both the post data and list of parties
            //it transforms the object into an array and runs
            $scope.loading = true;
            $scope.backgroundClass = "loading-background";
            pictureAndPostFactory.partyList().then(function(successResponse){
                $scope.partyList = Object.values(successResponse)
                $scope.partyListObject = successResponse;
                pictureAndPostFactory.postList($scope.address).then(function(successResponse){
                    if (successResponse == undefined){
                        console.log("test")
                        $scope.noData = true;
                        $scope.loading = false;
                        return
                    };
                    $scope.masterArray = $scope.originalArray.concat(successResponse);
                    $scope.originalArray = $scope.masterArray.slice();
                    $scope.loadMore();
                    $scope.backgroundClass = "";
                    $scope.loading = false;
                });
            });   
        };
        $scope.loadMore = function(){
            infiniteArray()
            if (($scope.data.length/100)==0){
                $scope.loadData()
            } else {
                offset()
                infiniteArray()
            }
        };

        function offset(){
            if (($scope.data.length%100)===0){
                $scope.address.offset += 100
                $scope.loadData();
            };
        };
        //this function slowly serves data from the masterArray
        function infiniteArray(){
            var last = [];
            var x
            if ( $scope.data.length == 0){
                x = -1
            }
            else {
                x = $scope.data.length - 1;
            }
            for(var i = 1; i <= 10; i++) {
                //check if the element is undefined, then there is no more data and the fuction can stop
                var currentValue = $scope.masterArray[(x+i)];
                if (currentValue == undefined){
                    return
                } else {
                	//this looks into the partylist and gets party logo and name an includes them in the currentValue object
                	currentValue["avatar"] = $scope.partyListObject[currentValue["code"]]["logo"]
                	currentValue["name"] = $scope.partyListObject[currentValue["code"]]["name"]["en"]
                    $scope.data.push(currentValue);
                }
            };
        }
        //determines if post if from FB or TW and creates an URL
        $scope.getUrl = function(data){
            var url = ""
            if (data.type.toUpperCase() == "TW"){
                url = "https://twitter.com/pirates/status/"+data.post_id
            }else{
                url = "https://facebook.com/"+data.post_id
            };
            return url
        };

        //function changes the text in the Platform selection button, based on wahat you've selected
        $scope.selectedPlatform = function() {
            var text = "Platform"
            switch ($scope.radioBtn){
                case "all":
                    text = "Platform"
                    break;
                case "tw":
                    text = "Twitter"
                    break;
                case "fb":
                    text = "Facebook"
                    break;
            };
            return text
        };
        
        $scope.textSize = function (text){
            if (text.length < 85){
                return true
            }
            else {
                return false
            }
        }

        //This function is called when filtering, so that new data is added into an empty array
        function resetArray(){
            $scope.masterArray = [];
            $scope.originalArray = [];
            $scope.data =[];
        };
        
        $scope.emptyInput = function(){
            $scope.partySelection = "";
        }

        //function that sorts entries by reach in ascending order
        //currently not available
        /*$scope.sortAscViews = function(){ 
            $scope.masterArray.sort(function(a, b){
            return a.post_likes > b.post_likes;
        });
        $scope.data = [];
        loadData();
        };*/

        //a toggle that sorts entries by reach in descending order
        $scope.sortDescViews = function(){
            resetArray()
            if ($("#asc-desc-views").hasClass("reach-selected")){
                $("#asc-desc-views").removeClass("reach-selected");
                $scope.address.sort = "";
                $scope.address.offset = 0;
                $scope.loadMore();
            } else {
                $("#asc-desc-views").addClass("reach-selected");
                $scope.address.sort = "likes";
                $scope.address.offset = 0;
                $scope.loadMore(); 
            };
        };

        $scope.defaultSort = function(){
            resetArray()
            $scope.address = {
                subType: "T",
                socialPlatform: "",
                sort: "",
                partyCode: "",
                offset: 0
            };
            $scope.loadMore(); 
            $(".up").removeClass("arrow-color")
            $(".down").removeClass("arrow-color")
            $("#asc-desc-views").removeClass("reach-selected");
        }
        //function that filters parties
        $scope.filterParty = function(code) {
            resetArray()
            $scope.address.partyCode = code;
            $scope.loadMore(); 
        }

        $scope.filterPlatform = function(platform){
            console.log("test2")
            if (platform == "all"){
                console.log("test")
                resetArray()
                $scope.address.socialPlatform = "";
                $scope.loadMore();
            } else {
                resetArray()
                $scope.address.socialPlatform = platform;
                $scope.loadMore();
            };      
        };
        //clicking enter after searching for party "clicks" on the first party in the list
        $("#party-selection-search").on('keydown', function (e) {
            if (e.keyCode == 13) {
                $(this).parent().next().click();
            };
        });

        $("#party-selection-button").click(function(){
            $("#party-selection-search").click().focus();
        });


        //function that toggles between ascending and descending amount of reach
        //the API currently doesn't support descending/ascending sort,so this code is pointless
        //If it is added, uncomment and change the function called by the element with ID asc-desc-views
        /*$scope.sortByViews = function(){
            if ( $("#asc-desc-views").hasClass("desc") ) {
                $("#asc-desc-views").removeClass("desc")
                $("#asc-desc-views").addClass("asc")
                $scope.sortAscViews()       
            }*/
            //same reason as above
           /* else {
                $("#asc-desc-views").addClass("toggled")
                $("#asc-desc-views").addClass("desc")
                $("#asc-desc-views").removeClass("asc")
                $scope.sortDescViews();
            };
        }*/

        //stops dropdown from closing)
        $('#party-selection-search').bind('click', function (e) { e.stopPropagation() })

    }]);
})(jQuery); // end of jQuery name space